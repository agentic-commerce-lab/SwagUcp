<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\KeyManagementService;
use SwagUcp\Service\SignatureVerificationService;

/**
 * Tests for SignatureVerificationService - JWT and Detached JWS operations.
 *
 * These tests verify compliance with:
 * - RFC 7515 (JSON Web Signature)
 * - RFC 7797 (JWS Unencoded Payload Option) for detached signatures
 * - ES256 (ECDSA P-256 + SHA-256) algorithm
 */
class SignatureVerificationServiceTest extends TestCase
{
    private SignatureVerificationService $service;
    private KeyManagementService $keyManagementService;
    private SystemConfigService $systemConfigService;

    private string $testPublicKeyPem;
    private string $testPrivateKeyPem;
    private string $testKeyId = 'test_key_2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->keyManagementService = new KeyManagementService($this->systemConfigService);
        $this->service = new SignatureVerificationService($this->keyManagementService);

        // Generate test keys
        $keyPair = $this->keyManagementService->generateEcP256KeyPair();
        $this->assertNotNull($keyPair, 'Test key generation failed');
        $this->testPublicKeyPem = $keyPair['public'];
        $this->testPrivateKeyPem = $keyPair['private'];
    }

    /**
     * Configure mocks to return test keys.
     */
    private function configureTestKeys(): void
    {
        $this->systemConfigService
            ->method('getString')
            ->willReturnCallback(function (string $key, string $salesChannelId) {
                return match ($key) {
                    'SwagUcp.config.ucpSigningKeyId' => $this->testKeyId,
                    'SwagUcp.config.ucpSigningPublicKey' => $this->testPublicKeyPem,
                    'SwagUcp.config.ucpSigningPrivateKey' => $this->testPrivateKeyPem,
                    default => '',
                };
            });
    }

    public function testCreateAndVerifyJwt(): void
    {
        $this->configureTestKeys();

        $payload = [
            'sub' => 'user123',
            'iat' => time(),
            'exp' => time() + 3600,
            'data' => ['test' => 'value'],
        ];

        // Create JWT
        $token = $this->service->createJwt($payload, 'test-channel');

        $this->assertNotEmpty($token);
        $this->assertCount(3, explode('.', $token), 'JWT should have 3 parts');

        // Verify JWT
        $verified = $this->service->verifyJwt($token, 'test-channel');

        $this->assertNotNull($verified, 'JWT verification should succeed');
        $this->assertEquals($payload['sub'], $verified['sub']);
        $this->assertEquals($payload['data'], $verified['data']);
    }

    public function testJwtHeaderContainsRequiredClaims(): void
    {
        $this->configureTestKeys();

        $token = $this->service->createJwt(['test' => 'data'], 'test-channel');

        // Parse header
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertEquals('ES256', $header['alg'], 'Algorithm must be ES256');
        $this->assertEquals('JWT', $header['typ'], 'Type must be JWT');
        $this->assertEquals($this->testKeyId, $header['kid'], 'Key ID must be included');
    }

    public function testVerifyJwtRejectsInvalidSignature(): void
    {
        $this->configureTestKeys();

        // Create a valid token then corrupt the signature
        $token = $this->service->createJwt(['test' => 'data'], 'test-channel');
        $parts = explode('.', $token);
        $parts[2] = 'invalid_signature_here';
        $corruptedToken = implode('.', $parts);

        $result = $this->service->verifyJwt($corruptedToken, 'test-channel');

        $this->assertNull($result, 'Should reject token with invalid signature');
    }

    public function testVerifyJwtRejectsInvalidFormat(): void
    {
        $this->configureTestKeys();

        $this->assertNull($this->service->verifyJwt('not.a.valid.jwt', 'test-channel'));
        $this->assertNull($this->service->verifyJwt('onlyonepart', 'test-channel'));
        $this->assertNull($this->service->verifyJwt('', 'test-channel'));
    }

    public function testCreateRequestSignatureProducesDetachedJws(): void
    {
        $this->configureTestKeys();

        $body = '{"order_id":"order123","event":"shipped"}';

        $signature = $this->service->createRequestSignature($body, 'test-channel');

        // Detached JWS format: header..signature (empty payload section)
        $parts = explode('.', $signature);
        $this->assertCount(3, $parts, 'Should have 3 parts');
        $this->assertEquals('', $parts[1], 'Payload section should be empty for detached JWS');

        // Verify header
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertEquals('ES256', $header['alg']);
        $this->assertEquals($this->testKeyId, $header['kid']);
    }

    public function testVerifyRequestSignature(): void
    {
        $this->configureTestKeys();

        $body = '{"order_id":"order123","event":"shipped","timestamp":"2026-01-13T12:00:00Z"}';

        // Create signature
        $signature = $this->service->createRequestSignature($body, 'test-channel');

        // Get public key in JWK format
        $jwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);
        $signerPublicKeys = [$jwk];

        // Verify signature
        $result = $this->service->verifyRequestSignature($signature, $body, $signerPublicKeys);

        $this->assertTrue($result, 'Valid signature should verify successfully');
    }

    public function testVerifyRequestSignatureRejectsTamperedBody(): void
    {
        $this->configureTestKeys();

        $originalBody = '{"order_id":"order123","event":"shipped"}';
        $tamperedBody = '{"order_id":"order999","event":"shipped"}';

        // Create signature for original body
        $signature = $this->service->createRequestSignature($originalBody, 'test-channel');

        // Get public key in JWK format
        $jwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);
        $signerPublicKeys = [$jwk];

        // Verify with tampered body
        $result = $this->service->verifyRequestSignature($signature, $tamperedBody, $signerPublicKeys);

        $this->assertFalse($result, 'Should reject signature when body is tampered');
    }

    public function testVerifyRequestSignatureRejectsWrongKey(): void
    {
        $this->configureTestKeys();

        $body = '{"test":"data"}';
        $signature = $this->service->createRequestSignature($body, 'test-channel');

        // Generate a different key pair
        $differentKeyPair = $this->keyManagementService->generateEcP256KeyPair();
        $wrongJwk = $this->keyManagementService->pemToJwk($differentKeyPair['public'], 'wrong_key');

        $result = $this->service->verifyRequestSignature($signature, $body, [$wrongJwk]);

        $this->assertFalse($result, 'Should reject signature verified with wrong key');
    }

    public function testVerifyRequestSignatureFindsKeyByKid(): void
    {
        $this->configureTestKeys();

        $body = '{"test":"data"}';
        $signature = $this->service->createRequestSignature($body, 'test-channel');

        // Create array with wrong key first, then correct key
        $wrongKeyPair = $this->keyManagementService->generateEcP256KeyPair();
        $wrongJwk = $this->keyManagementService->pemToJwk($wrongKeyPair['public'], 'wrong_key');
        $correctJwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);

        $signerPublicKeys = [$wrongJwk, $correctJwk];

        $result = $this->service->verifyRequestSignature($signature, $body, $signerPublicKeys);

        $this->assertTrue($result, 'Should find correct key by kid');
    }

    public function testMerchantAuthorizationSignAndVerify(): void
    {
        $this->configureTestKeys();

        $checkout = [
            'id' => 'chk_abc123',
            'status' => 'ready_for_complete',
            'currency' => 'EUR',
            'line_items' => [
                ['item' => ['id' => 'prod1', 'title' => 'Test', 'price' => 1000], 'quantity' => 1]
            ],
            'totals' => [
                ['type' => 'total', 'amount' => 1190]
            ],
        ];

        // Sign
        $signature = $this->service->signMerchantAuthorization($checkout, 'test-channel');

        $this->assertNotEmpty($signature);
        $this->assertStringContainsString('..', $signature, 'Should be detached JWS format');

        // Add signature to checkout
        $checkout['ap2'] = ['merchant_authorization' => $signature];

        // Verify
        $jwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);
        $merchantPublicKeys = [$jwk];

        $result = $this->service->verifyMerchantAuthorization($checkout, $merchantPublicKeys);

        $this->assertTrue($result, 'Merchant authorization should verify');
    }

    public function testMerchantAuthorizationExcludesAp2Field(): void
    {
        $this->configureTestKeys();

        // Create checkout with existing ap2 field (should be excluded from signature)
        $checkout = [
            'id' => 'chk_test',
            'status' => 'ready_for_complete',
            'ap2' => ['some_existing_data' => 'value'],
        ];

        $signature = $this->service->signMerchantAuthorization($checkout, 'test-channel');

        // The signature should be the same as if ap2 wasn't there
        $checkoutWithoutAp2 = [
            'id' => 'chk_test',
            'status' => 'ready_for_complete',
        ];

        $signature2 = $this->service->signMerchantAuthorization($checkoutWithoutAp2, 'test-channel');

        // Both signatures should verify against the checkout without ap2
        // (Note: signatures won't be identical due to potential timing, but both should verify)
        $jwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);

        $checkoutWithSignature = array_merge($checkoutWithoutAp2, ['ap2' => ['merchant_authorization' => $signature]]);
        $this->assertTrue($this->service->verifyMerchantAuthorization($checkoutWithSignature, [$jwk]));
    }

    public function testVerifyRequestSignatureRejectsInvalidAlgorithm(): void
    {
        // Create a fake JWT header with wrong algorithm
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])); // Wrong algorithm
        $signature = $header . '..' . 'fake_signature';

        $jwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);

        $result = $this->service->verifyRequestSignature($signature, '{"test":"data"}', [$jwk]);

        $this->assertFalse($result, 'Should reject non-ES256 algorithms');
    }

    public function testEs256SignatureIs64Bytes(): void
    {
        $this->configureTestKeys();

        $token = $this->service->createJwt(['test' => 'data'], 'test-channel');
        $parts = explode('.', $token);

        // Decode signature
        $signatureB64 = $parts[2];
        $signatureRaw = base64_decode(strtr($signatureB64, '-_', '+/'));

        // ES256 raw signature should be 64 bytes (32 bytes r + 32 bytes s)
        $this->assertEquals(64, strlen($signatureRaw), 'ES256 signature should be 64 bytes');
    }

    public function testSignatureVerificationIsDeterministic(): void
    {
        $this->configureTestKeys();

        $body = '{"deterministic":"test"}';

        // Create signature
        $signature = $this->service->createRequestSignature($body, 'test-channel');

        // Verify multiple times
        $jwk = $this->keyManagementService->pemToJwk($this->testPublicKeyPem, $this->testKeyId);

        for ($i = 0; $i < 5; $i++) {
            $result = $this->service->verifyRequestSignature($signature, $body, [$jwk]);
            $this->assertTrue($result, "Verification should be deterministic (iteration $i)");
        }
    }

    public function testCreateJwtWithoutPrivateKeyThrowsException(): void
    {
        // Don't configure keys - private key will be null
        $this->systemConfigService
            ->method('getString')
            ->willReturn('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Private key not configured');

        $this->service->createJwt(['test' => 'data'], 'test-channel');
    }
}
