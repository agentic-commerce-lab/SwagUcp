<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\KeyManagementService;
use SwagUcp\Service\SignatureVerificationService;

/**
 * UCP Protocol Compliance Tests
 * 
 * These tests verify that the implementation conforms to the UCP specification
 * for cryptographic operations, including:
 * 
 * - ES256 algorithm compliance (ECDSA P-256 + SHA-256)
 * - Detached JWS format (RFC 7797)
 * - Request-Signature header format
 * - Merchant Authorization signing
 * - Key ID (kid) handling
 */
class SecurityProtocolComplianceTest extends TestCase
{
    private KeyManagementService $keyManagement;
    private SignatureVerificationService $signatureService;
    private SystemConfigService $configService;
    
    private string $privateKeyPem;
    private string $publicKeyPem;
    private string $keyId = 'merchant_2026_01';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->configService = $this->createMock(SystemConfigService::class);
        $this->keyManagement = new KeyManagementService($this->configService);
        $this->signatureService = new SignatureVerificationService($this->keyManagement);
        
        // Generate test keys
        $keys = $this->keyManagement->generateEcP256KeyPair();
        $this->privateKeyPem = $keys['private'];
        $this->publicKeyPem = $keys['public'];
        
        // Configure mocks
        $this->configService
            ->method('getString')
            ->willReturnCallback(fn(string $key) => match($key) {
                'SwagUcp.config.ucpSigningKeyId' => $this->keyId,
                'SwagUcp.config.ucpSigningPublicKey' => $this->publicKeyPem,
                'SwagUcp.config.ucpSigningPrivateKey' => $this->privateKeyPem,
                default => '',
            });
    }

    /**
     * @test
     * UCP Spec: "All signatures MUST use ES256 (ECDSA using P-256 curve and SHA-256)"
     */
    public function signatureUsesEs256Algorithm(): void
    {
        $signature = $this->signatureService->createRequestSignature('{"test":"data"}', 'channel');
        
        // Parse header
        $parts = explode('.', $signature);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        
        $this->assertEquals('ES256', $header['alg']);
    }

    /**
     * @test
     * UCP Spec: "Include the key ID in the JWT header's `kid` claim"
     */
    public function signatureIncludesKeyIdInHeader(): void
    {
        $signature = $this->signatureService->createRequestSignature('{"test":"data"}', 'channel');
        
        $parts = explode('.', $signature);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        
        $this->assertArrayHasKey('kid', $header);
        $this->assertEquals($this->keyId, $header['kid']);
    }

    /**
     * @test
     * UCP Spec: "Create a detached JWT (RFC 7797) over the request body"
     * Detached format: header..signature (empty payload section)
     */
    public function requestSignatureUsesDetachedJwsFormat(): void
    {
        $signature = $this->signatureService->createRequestSignature('{"body":"content"}', 'channel');
        
        $parts = explode('.', $signature);
        
        $this->assertCount(3, $parts, 'Must have 3 parts: header.payload.signature');
        $this->assertEquals('', $parts[1], 'Payload section must be empty for detached JWS');
        $this->assertNotEmpty($parts[0], 'Header must not be empty');
        $this->assertNotEmpty($parts[2], 'Signature must not be empty');
    }

    /**
     * @test
     * UCP Spec: Verification should reconstruct signing input as header + "." + base64url(payload)
     */
    public function verificationReconstructsSigningInput(): void
    {
        $body = '{"event":"order.shipped","order_id":"12345"}';
        
        // Create signature
        $signature = $this->signatureService->createRequestSignature($body, 'channel');
        
        // Get public key JWK
        $jwk = $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId);
        
        // Verify - internally this reconstructs: header + "." + base64url(body)
        $valid = $this->signatureService->verifyRequestSignature($signature, $body, [$jwk]);
        
        $this->assertTrue($valid);
    }

    /**
     * @test
     * UCP Spec: JWK public keys must include kid, kty, crv, x, y, use, alg
     */
    public function jwkHasRequiredFields(): void
    {
        $jwk = $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId);
        
        $requiredFields = ['kid', 'kty', 'crv', 'x', 'y', 'use', 'alg'];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $jwk, "JWK must contain '$field' field");
        }
        
        $this->assertEquals('EC', $jwk['kty']);
        $this->assertEquals('P-256', $jwk['crv']);
        $this->assertEquals('sig', $jwk['use']);
        $this->assertEquals('ES256', $jwk['alg']);
    }

    /**
     * @test
     * UCP Spec: Merchant authorization uses detached JWS format header..signature
     */
    public function merchantAuthorizationUsesDetachedFormat(): void
    {
        $checkout = [
            'id' => 'chk_abc123',
            'status' => 'ready_for_complete',
            'totals' => [['type' => 'total', 'amount' => 1000]],
        ];
        
        $signature = $this->signatureService->signMerchantAuthorization($checkout, 'channel');
        
        // Should be detached format: header..signature
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.\.[A-Za-z0-9_-]+$/', $signature);
    }

    /**
     * @test
     * UCP Spec: "The signature MUST cover both the JWS header and the checkout payload"
     */
    public function merchantAuthorizationCoversEntirePayload(): void
    {
        $checkout = [
            'id' => 'chk_xyz',
            'status' => 'ready_for_complete',
            'currency' => 'EUR',
            'line_items' => [
                ['item' => ['id' => 'p1', 'price' => 1000], 'quantity' => 2]
            ],
            'totals' => [['type' => 'total', 'amount' => 2000]],
        ];
        
        $signature = $this->signatureService->signMerchantAuthorization($checkout, 'channel');
        $checkout['ap2'] = ['merchant_authorization' => $signature];
        
        $jwk = $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId);
        
        // Valid checkout should verify
        $this->assertTrue($this->signatureService->verifyMerchantAuthorization($checkout, [$jwk]));
        
        // Modified checkout should NOT verify
        $modifiedCheckout = $checkout;
        $modifiedCheckout['totals'][0]['amount'] = 9999;
        $this->assertFalse($this->signatureService->verifyMerchantAuthorization($modifiedCheckout, [$jwk]));
    }

    /**
     * @test
     * UCP Spec: ap2 field should be excluded when computing merchant authorization signature
     */
    public function merchantAuthorizationExcludesAp2Field(): void
    {
        $checkout = [
            'id' => 'chk_test',
            'status' => 'ready_for_complete',
            'ap2' => ['existing_data' => 'should_be_ignored'],
        ];
        
        $signature = $this->signatureService->signMerchantAuthorization($checkout, 'channel');
        
        // Set the signature in ap2
        $checkout['ap2'] = ['merchant_authorization' => $signature];
        
        $jwk = $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId);
        
        // Should verify even though checkout had existing ap2 data
        $this->assertTrue($this->signatureService->verifyMerchantAuthorization($checkout, [$jwk]));
    }

    /**
     * @test
     * UCP Spec: Verifier should locate key by kid claim
     */
    public function verifierLocatesKeyByKid(): void
    {
        $body = '{"test":"data"}';
        $signature = $this->signatureService->createRequestSignature($body, 'channel');
        
        // Generate additional keys to create a multi-key array
        $keys2 = $this->keyManagement->generateEcP256KeyPair();
        $keys3 = $this->keyManagement->generateEcP256KeyPair();
        
        // Create array with correct key in middle
        $publicKeys = [
            $this->keyManagement->pemToJwk($keys2['public'], 'wrong_key_1'),
            $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId), // Correct key
            $this->keyManagement->pemToJwk($keys3['public'], 'wrong_key_2'),
        ];
        
        $valid = $this->signatureService->verifyRequestSignature($signature, $body, $publicKeys);
        
        $this->assertTrue($valid, 'Should find correct key by kid');
    }

    /**
     * @test
     * UCP Spec: Only ES256, ES384, ES512 algorithms are allowed
     */
    public function verifierRejectsUnsupportedAlgorithms(): void
    {
        // Create a fake detached JWS with unsupported algorithm
        $fakeHeader = base64_encode(json_encode([
            'alg' => 'RS256', // RSA algorithm - not allowed
            'typ' => 'JWT',
            'kid' => $this->keyId,
        ]));
        $fakeSignature = $fakeHeader . '..' . base64_encode('fake_sig');
        
        $jwk = $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId);
        
        $valid = $this->signatureService->verifyRequestSignature($fakeSignature, '{}', [$jwk]);
        
        $this->assertFalse($valid, 'Should reject RS256 algorithm');
        
        // Test with HS256
        $fakeHeader = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $fakeSignature = $fakeHeader . '..' . base64_encode('fake_sig');
        
        $valid = $this->signatureService->verifyRequestSignature($fakeSignature, '{}', [$jwk]);
        
        $this->assertFalse($valid, 'Should reject HS256 algorithm');
    }

    /**
     * @test
     * Cross-platform interoperability: Keys and signatures should be standard format
     */
    public function generatedKeysAreInteroperable(): void
    {
        // Generate key
        $keyPair = $this->keyManagement->generateEcP256KeyPair();
        
        // Convert to JWK
        $jwk = $this->keyManagement->pemToJwk($keyPair['public'], 'interop_test');
        
        // Verify JWK values are base64url encoded without padding
        $this->assertDoesNotMatchRegularExpression('/[+=\/]/', $jwk['x'], 'x should be base64url');
        $this->assertDoesNotMatchRegularExpression('/[+=\/]/', $jwk['y'], 'y should be base64url');
        
        // Convert back to PEM
        $restoredPem = $this->keyManagement->jwkToPem($jwk);
        
        // Verify restored key works with OpenSSL
        $key = openssl_pkey_get_public($restoredPem);
        $this->assertNotFalse($key, 'Restored PEM should be valid OpenSSL key');
        
        // Verify key details
        $details = openssl_pkey_get_details($key);
        $this->assertEquals(OPENSSL_KEYTYPE_EC, $details['type']);
    }

    /**
     * @test
     * Security: Signature verification should be constant-time resistant
     */
    public function signatureVerificationHandlesMalformedInput(): void
    {
        $jwk = $this->keyManagement->pemToJwk($this->publicKeyPem, $this->keyId);
        
        // Test various malformed inputs - should all return false without throwing
        $malformedInputs = [
            '',
            '.',
            '..',
            '...',
            'not_base64!@#$',
            base64_encode('{}') . '..',
            '..' . base64_encode('sig'),
        ];
        
        foreach ($malformedInputs as $input) {
            $result = $this->signatureService->verifyRequestSignature($input, '{}', [$jwk]);
            $this->assertFalse($result, "Should gracefully reject malformed input: '$input'");
        }
    }

    /**
     * @test
     * RFC 7515 compliance: Base64url encoding must not have padding
     */
    public function base64UrlEncodingHasNoPadding(): void
    {
        // Create a signature
        $signature = $this->signatureService->createRequestSignature('{"test":"data"}', 'channel');
        
        $parts = explode('.', $signature);
        
        // No part should contain = (padding) or + / (standard base64)
        foreach ([$parts[0], $parts[2]] as $part) {
            $this->assertStringNotContainsString('=', $part, 'Should not have padding');
            $this->assertStringNotContainsString('+', $part, 'Should use - instead of +');
            $this->assertStringNotContainsString('/', $part, 'Should use _ instead of /');
        }
    }
}
