<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\KeyManagementService;

/**
 * Tests for KeyManagementService - EC P-256 key generation and conversion.
 */
class KeyManagementServiceTest extends TestCase
{
    private KeyManagementService $service;
    private SystemConfigService $systemConfigService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->service = new KeyManagementService($this->systemConfigService);
    }

    public function testGenerateEcP256KeyPair(): void
    {
        $keyPair = $this->service->generateEcP256KeyPair();

        $this->assertNotNull($keyPair, 'Key pair generation should succeed');
        $this->assertArrayHasKey('public', $keyPair);
        $this->assertArrayHasKey('private', $keyPair);
        
        // Verify PEM format
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $keyPair['public']);
        $this->assertStringContainsString('-----END PUBLIC KEY-----', $keyPair['public']);
        // Note: OpenSSL may use either EC-specific or PKCS#8 format
        $this->assertTrue(
            str_contains($keyPair['private'], '-----BEGIN EC PRIVATE KEY-----') ||
            str_contains($keyPair['private'], '-----BEGIN PRIVATE KEY-----'),
            'Private key should be in PEM format'
        );
        
        // Verify keys are valid
        $publicKey = openssl_pkey_get_public($keyPair['public']);
        $this->assertNotFalse($publicKey, 'Generated public key should be valid');
        
        $privateKey = openssl_pkey_get_private($keyPair['private']);
        $this->assertNotFalse($privateKey, 'Generated private key should be valid');
        
        // Verify it's EC P-256
        $details = openssl_pkey_get_details($privateKey);
        $this->assertEquals(OPENSSL_KEYTYPE_EC, $details['type']);
        $this->assertArrayHasKey('ec', $details);
    }

    public function testPemToJwkConversion(): void
    {
        // Generate a key pair first
        $keyPair = $this->service->generateEcP256KeyPair();
        $this->assertNotNull($keyPair);

        $keyId = 'test_key_123';
        $jwk = $this->service->pemToJwk($keyPair['public'], $keyId);

        $this->assertNotNull($jwk, 'PEM to JWK conversion should succeed');
        $this->assertEquals($keyId, $jwk['kid']);
        $this->assertEquals('EC', $jwk['kty']);
        $this->assertEquals('P-256', $jwk['crv']);
        $this->assertEquals('ES256', $jwk['alg']);
        $this->assertEquals('sig', $jwk['use']);
        
        // x and y should be base64url encoded 32-byte values
        $this->assertNotEmpty($jwk['x']);
        $this->assertNotEmpty($jwk['y']);
        
        // Verify no padding (base64url)
        $this->assertStringNotContainsString('=', $jwk['x']);
        $this->assertStringNotContainsString('=', $jwk['y']);
    }

    public function testJwkToPemConversion(): void
    {
        // Generate a key pair
        $keyPair = $this->service->generateEcP256KeyPair();
        $this->assertNotNull($keyPair);

        // Convert to JWK
        $jwk = $this->service->pemToJwk($keyPair['public'], 'test_key');
        $this->assertNotNull($jwk);

        // Convert back to PEM
        $pemRestored = $this->service->jwkToPem($jwk);
        $this->assertNotNull($pemRestored, 'JWK to PEM conversion should succeed');
        
        // Verify the restored PEM is valid
        $key = openssl_pkey_get_public($pemRestored);
        $this->assertNotFalse($key, 'Restored PEM should be a valid public key');
        
        // Verify the keys are equivalent by checking details
        $originalKey = openssl_pkey_get_public($keyPair['public']);
        $originalDetails = openssl_pkey_get_details($originalKey);
        $restoredDetails = openssl_pkey_get_details($key);
        
        $this->assertEquals($originalDetails['ec']['x'], $restoredDetails['ec']['x']);
        $this->assertEquals($originalDetails['ec']['y'], $restoredDetails['ec']['y']);
    }

    public function testJwkToPemRejectsInvalidAlgorithm(): void
    {
        $invalidJwk = [
            'kty' => 'RSA', // Wrong key type
            'crv' => 'P-256',
            'x' => 'some_x',
            'y' => 'some_y',
        ];

        $result = $this->service->jwkToPem($invalidJwk);
        $this->assertNull($result, 'Should reject non-EC keys');
    }

    public function testJwkToPemRejectsInvalidCurve(): void
    {
        $invalidJwk = [
            'kty' => 'EC',
            'crv' => 'P-384', // Wrong curve
            'x' => 'some_x',
            'y' => 'some_y',
        ];

        $result = $this->service->jwkToPem($invalidJwk);
        $this->assertNull($result, 'Should reject non-P-256 curves');
    }

    public function testJwkToPemRejectsMissingCoordinates(): void
    {
        $invalidJwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            // Missing x and y
        ];

        $result = $this->service->jwkToPem($invalidJwk);
        $this->assertNull($result, 'Should reject JWK without coordinates');
    }

    public function testGetKeyIdDefaultsToYearBasedId(): void
    {
        $this->systemConfigService
            ->expects($this->once())
            ->method('getString')
            ->with('SwagUcp.config.ucpSigningKeyId', 'test-channel')
            ->willReturn('');

        $keyId = $this->service->getKeyId('test-channel');
        
        $this->assertStringStartsWith('shopware_ucp_', $keyId);
        $this->assertStringContainsString(date('Y'), $keyId);
    }

    public function testGetKeyIdReturnsConfiguredValue(): void
    {
        $this->systemConfigService
            ->expects($this->once())
            ->method('getString')
            ->with('SwagUcp.config.ucpSigningKeyId', 'test-channel')
            ->willReturn('custom_key_id');

        $keyId = $this->service->getKeyId('test-channel');
        
        $this->assertEquals('custom_key_id', $keyId);
    }

    public function testRoundTripPemToJwkToPem(): void
    {
        // This test verifies that PEM -> JWK -> PEM produces functionally equivalent keys
        $keyPair = $this->service->generateEcP256KeyPair();
        $this->assertNotNull($keyPair);

        // First conversion: PEM to JWK
        $jwk = $this->service->pemToJwk($keyPair['public'], 'roundtrip_key');
        $this->assertNotNull($jwk);

        // Second conversion: JWK to PEM
        $pemRestored = $this->service->jwkToPem($jwk);
        $this->assertNotNull($pemRestored);

        // Third conversion: PEM to JWK again
        $jwk2 = $this->service->pemToJwk($pemRestored, 'roundtrip_key');
        $this->assertNotNull($jwk2);

        // The JWK x and y values should match
        $this->assertEquals($jwk['x'], $jwk2['x']);
        $this->assertEquals($jwk['y'], $jwk2['y']);
    }
}
