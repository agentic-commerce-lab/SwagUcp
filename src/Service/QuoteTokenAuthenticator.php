<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Doctrine\DBAL\Connection;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Ucp;
use Symfony\Component\Clock\NativeClock;

/**
 * Resource-server side of dev.ucp.common.identity_linking: validates OAuth 2.0
 * Bearer access tokens issued by the standalone SwagUcpIdentityLinking plugin.
 *
 * A valid token replaces the whole legacy authorization chain (agent
 * signature, buyer claim, per-customer swag_ucp_agent_authorization record):
 * the customer's OAuth consent IS the authorization, and the token's `sub`
 * claim names the customer the agent acts for.
 *
 * Deliberately has zero class dependencies on the OAuth plugin - it verifies
 * against the published public key (SystemConfig) and the token table (DBAL),
 * both stable interop points. lcobucci/jwt ships with shopware/core.
 */
class QuoteTokenAuthenticator
{
    public const SCOPE_QUOTE = 'quote';

    private const CONFIG_PUBLIC_KEY = 'SwagUcpIdentityLinking.config.publicKey';
    private const ACCESS_TOKEN_TABLE = 'swag_oauth_access_token';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists(Ucp::IDENTITY_LINKING_PLUGIN_CLASS)
            && $this->systemConfigService->getString(self::CONFIG_PUBLIC_KEY) !== '';
    }

    /**
     * Validate a Bearer access token and return the customer id it acts for.
     *
     * @throws QuoteAccessException invalid_token (401) or insufficient_scope (403)
     */
    public function authenticate(string $bearerToken): string
    {
        if (!$this->isAvailable()) {
            throw QuoteAccessException::invalidToken('Identity linking is not available on this shop');
        }

        $token = $this->parseAndVerify($bearerToken);
        $claims = $token->claims();

        $customerId = (string) $claims->get('sub', '');
        $tokenId = (string) $claims->get('jti', '');

        if ($customerId === '' || $tokenId === '' || $this->isRevoked($tokenId)) {
            throw QuoteAccessException::invalidToken('Access token is invalid, expired, or revoked');
        }

        $scopes = $claims->get('scopes', []);
        if (!\is_array($scopes) || !\in_array(self::SCOPE_QUOTE, $scopes, true)) {
            throw QuoteAccessException::insufficientScope();
        }

        return $customerId;
    }

    private function parseAndVerify(string $bearerToken): UnencryptedToken
    {
        $publicKey = $this->systemConfigService->getString(self::CONFIG_PUBLIC_KEY);

        try {
            $token = (new Parser(new JoseEncoder()))->parse($bearerToken);

            (new Validator())->assert(
                $token,
                new SignedWith(new Sha256(), InMemory::plainText($publicKey)),
                new StrictValidAt(new NativeClock()),
            );
        } catch (\Throwable) {
            // Never echo token contents or parse errors back to the caller
            throw QuoteAccessException::invalidToken('Access token is invalid, expired, or revoked');
        }

        \assert($token instanceof UnencryptedToken);

        return $token;
    }

    private function isRevoked(string $tokenId): bool
    {
        try {
            $revoked = $this->connection->fetchOne(
                'SELECT revoked FROM ' . self::ACCESS_TOKEN_TABLE . ' WHERE identifier = :identifier',
                ['identifier' => $tokenId]
            );
        } catch (\Throwable) {
            return true; // token table unreachable: fail closed
        }

        // Unknown identifiers count as revoked: fail closed
        return $revoked === false || (int) $revoked === 1;
    }
}
