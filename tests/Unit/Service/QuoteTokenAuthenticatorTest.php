<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\QuoteAccessException;
use SwagUcp\Service\QuoteTokenAuthenticator;

class QuoteTokenAuthenticatorTest extends TestCase
{
    private static string $privateKey;
    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert($resource !== false);
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);
        \assert($details !== false);

        self::$privateKey = $privateKey;
        self::$publicKey = $details['key'];
    }

    public function testUnavailableWithoutIdentityLinkingPlugin(): void
    {
        // The SwagUcpIdentityLinking plugin class does not exist in this test env
        $authenticator = $this->authenticator(revoked: '0');

        $this->assertFalse($authenticator->isAvailable());

        $this->expectException(QuoteAccessException::class);
        $authenticator->authenticate('anything');
    }

    public function testValidTokenReturnsCustomerId(): void
    {
        $authenticator = $this->availableAuthenticator(revoked: '0');

        $customerId = $authenticator->authenticate($this->jwt(scopes: ['quote']));

        $this->assertSame('customer-id', $customerId);
    }

    public function testExpiredTokenRejected(): void
    {
        $authenticator = $this->availableAuthenticator(revoked: '0');

        try {
            $authenticator->authenticate($this->jwt(scopes: ['quote'], expired: true));
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('invalid_token', $e->getErrorCode());
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testTokenSignedWithForeignKeyRejected(): void
    {
        $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \assert($foreign !== false);
        openssl_pkey_export($foreign, $foreignPrivate);

        $authenticator = $this->availableAuthenticator(revoked: '0');

        try {
            $authenticator->authenticate($this->jwt(scopes: ['quote'], privateKey: $foreignPrivate));
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('invalid_token', $e->getErrorCode());
        }
    }

    public function testRevokedTokenRejected(): void
    {
        $authenticator = $this->availableAuthenticator(revoked: '1');

        try {
            $authenticator->authenticate($this->jwt(scopes: ['quote']));
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('invalid_token', $e->getErrorCode());
        }
    }

    public function testUnknownTokenIdentifierFailsClosed(): void
    {
        $authenticator = $this->availableAuthenticator(revoked: false);

        $this->expectException(QuoteAccessException::class);
        $authenticator->authenticate($this->jwt(scopes: ['quote']));
    }

    public function testMissingQuoteScopeRejectedWithInsufficientScope(): void
    {
        $authenticator = $this->availableAuthenticator(revoked: '0');

        try {
            $authenticator->authenticate($this->jwt(scopes: ['checkout']));
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('insufficient_scope', $e->getErrorCode());
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testGarbageTokenRejected(): void
    {
        $authenticator = $this->availableAuthenticator(revoked: '0');

        $this->expectException(QuoteAccessException::class);
        $authenticator->authenticate('not-a-jwt');
    }

    /**
     * @param list<string> $scopes
     */
    private function jwt(array $scopes, bool $expired = false, ?string $privateKey = null): string
    {
        $now = new \DateTimeImmutable();
        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->identifiedBy('token-id')
            ->permittedFor('client-id')
            ->relatedTo('customer-id')
            ->issuedAt($now->modify('-2 minutes'))
            ->canOnlyBeUsedAfter($now->modify('-2 minutes'))
            ->expiresAt($expired ? $now->modify('-1 minute') : $now->modify('+1 hour'))
            ->withClaim('scopes', $scopes);

        return $builder
            ->getToken(new Sha256(), InMemory::plainText($privateKey ?? self::$privateKey))
            ->toString();
    }

    /**
     * @param string|false $revoked value fetchOne returns for the revocation lookup
     */
    private function authenticator(string|false $revoked, string $publicKey = ''): QuoteTokenAuthenticator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturn($publicKey);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($revoked);

        return new QuoteTokenAuthenticator($systemConfig, $connection);
    }

    /**
     * The plugin class is absent in unit tests, so isAvailable() is stubbed
     * through an anonymous subclass to exercise the token validation itself.
     *
     * @param string|false $revoked
     */
    private function availableAuthenticator(string|false $revoked): QuoteTokenAuthenticator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturn(self::$publicKey);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($revoked);

        return new class($systemConfig, $connection) extends QuoteTokenAuthenticator {
            public function isAvailable(): bool
            {
                return true;
            }
        };
    }
}
