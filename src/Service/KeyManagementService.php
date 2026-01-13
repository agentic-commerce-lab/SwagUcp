<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Key Management Service for UCP cryptographic operations.
 *
 * Handles EC P-256 key generation, storage, and conversion between
 * PEM and JWK formats as required by the UCP specification.
 */
class KeyManagementService
{
    use Base64UrlTrait;

    private const CONFIG_KEY_ID = 'SwagUcp.config.ucpSigningKeyId';
    private const CONFIG_PUBLIC_KEY = 'SwagUcp.config.ucpSigningPublicKey';
    private const CONFIG_PRIVATE_KEY = 'SwagUcp.config.ucpSigningPrivateKey';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Get public keys in JWK format for the UCP discovery profile.
     *
     * @param string $salesChannelId Sales channel ID
     *
     * @return list<array<string, string>> Array of JWK public keys
     */
    public function getPublicKeys(string $salesChannelId): array
    {
        $keyId = $this->getKeyId($salesChannelId);
        $publicKeyPem = $this->getPublicKeyPem($salesChannelId);

        if ($publicKeyPem === null || $publicKeyPem === '') {
            // Generate and store new keys if not configured
            $this->generateAndStoreKeys($salesChannelId);
            $publicKeyPem = $this->getPublicKeyPem($salesChannelId);
            $keyId = $this->getKeyId($salesChannelId);
        }

        if ($publicKeyPem === null) {
            return [];
        }

        $jwk = $this->pemToJwk($publicKeyPem, $keyId);

        return $jwk !== null ? [$jwk] : [];
    }

    /**
     * Get the private key in PEM format.
     *
     * @param string $salesChannelId Sales channel ID
     *
     * @return string|null Private key PEM or null if not configured
     */
    public function getPrivateKey(string $salesChannelId): ?string
    {
        $privateKey = $this->systemConfigService->getString(
            self::CONFIG_PRIVATE_KEY,
            $salesChannelId
        );

        return !empty($privateKey) ? $privateKey : null;
    }

    /**
     * Get the public key in PEM format.
     *
     * @param string $salesChannelId Sales channel ID
     *
     * @return string|null Public key PEM or null if not configured
     */
    public function getPublicKeyPem(string $salesChannelId): ?string
    {
        $publicKey = $this->systemConfigService->getString(
            self::CONFIG_PUBLIC_KEY,
            $salesChannelId
        );

        return !empty($publicKey) ? $publicKey : null;
    }

    /**
     * Get the key ID for the current signing key.
     *
     * @param string $salesChannelId Sales channel ID
     *
     * @return string Key ID
     */
    public function getKeyId(string $salesChannelId): string
    {
        $keyId = $this->systemConfigService->getString(
            self::CONFIG_KEY_ID,
            $salesChannelId
        );

        return !empty($keyId) ? $keyId : 'shopware_ucp_' . date('Y');
    }

    /**
     * Generate a new EC P-256 key pair and store it in system config.
     *
     * @param string $salesChannelId Sales channel ID
     */
    public function generateAndStoreKeys(string $salesChannelId): void
    {
        $keyPair = $this->generateEcP256KeyPair();

        if ($keyPair === null) {
            throw new \RuntimeException('Failed to generate EC P-256 key pair');
        }

        $keyId = 'shopware_ucp_' . bin2hex(random_bytes(8));

        $this->systemConfigService->set(self::CONFIG_KEY_ID, $keyId, $salesChannelId);
        $this->systemConfigService->set(self::CONFIG_PUBLIC_KEY, $keyPair['public'], $salesChannelId);
        $this->systemConfigService->set(self::CONFIG_PRIVATE_KEY, $keyPair['private'], $salesChannelId);
    }

    /**
     * Generate a new EC P-256 (secp256r1) key pair.
     *
     * @return array{public: string, private: string}|null Key pair in PEM format
     */
    public function generateEcP256KeyPair(): ?array
    {
        $config = [
            'curve_name' => 'prime256v1', // P-256
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
        ];

        $privateKey = openssl_pkey_new($config);
        if ($privateKey === false) {
            return null;
        }

        // Export private key
        $privateKeyPem = '';
        if (!openssl_pkey_export($privateKey, $privateKeyPem)) {
            return null;
        }

        // Get public key
        $details = openssl_pkey_get_details($privateKey);
        if ($details === false) {
            return null;
        }

        return [
            'public' => $details['key'],
            'private' => $privateKeyPem,
        ];
    }

    /**
     * Convert PEM public key to JWK format.
     *
     * @param string $publicKeyPem Public key in PEM format
     * @param string $keyId Key ID for the JWK
     *
     * @return array<string, string>|null JWK array or null on failure
     */
    public function pemToJwk(string $publicKeyPem, string $keyId): ?array
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec'])) {
            return null;
        }

        $ec = $details['ec'];

        // Ensure x and y are exactly 32 bytes (P-256)
        $x = str_pad($ec['x'], 32, "\x00", \STR_PAD_LEFT);
        $y = str_pad($ec['y'], 32, "\x00", \STR_PAD_LEFT);

        return [
            'kid' => $keyId,
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $this->base64UrlEncode($x),
            'y' => $this->base64UrlEncode($y),
            'use' => 'sig',
            'alg' => 'ES256',
        ];
    }

    /**
     * Convert JWK to PEM public key format.
     *
     * @param array<string, string> $jwk JWK array with EC P-256 key
     *
     * @return string|null PEM public key or null on failure
     */
    public function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') {
            return null;
        }

        if (!isset($jwk['x']) || !isset($jwk['y'])) {
            return null;
        }

        $x = $this->base64UrlDecode($jwk['x']);
        $y = $this->base64UrlDecode($jwk['y']);

        if ($x === false || $y === false) {
            return null;
        }

        // Pad to 32 bytes
        $x = str_pad($x, 32, "\x00", \STR_PAD_LEFT);
        $y = str_pad($y, 32, "\x00", \STR_PAD_LEFT);

        // Build uncompressed EC point (0x04 || x || y)
        $point = "\x04" . $x . $y;

        // Build DER-encoded SubjectPublicKeyInfo for P-256
        // SEQUENCE {
        //   SEQUENCE {
        //     OBJECT IDENTIFIER ecPublicKey (1.2.840.10045.2.1)
        //     OBJECT IDENTIFIER prime256v1 (1.2.840.10045.3.1.7)
        //   }
        //   BIT STRING (point)
        // }
        $ecPublicKeyOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // 1.2.840.10045.2.1
        $prime256v1Oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // 1.2.840.10045.3.1.7

        $algorithmIdentifier = "\x30" . \chr(\strlen($ecPublicKeyOid) + \strlen($prime256v1Oid))
            . $ecPublicKeyOid . $prime256v1Oid;

        // BIT STRING: 0x00 prefix (no unused bits) + point
        $bitString = "\x03" . \chr(\strlen($point) + 1) . "\x00" . $point;

        $der = "\x30" . \chr(\strlen($algorithmIdentifier) + \strlen($bitString))
            . $algorithmIdentifier . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= '-----END PUBLIC KEY-----';

        // Verify the generated PEM is valid
        $testKey = openssl_pkey_get_public($pem);
        if ($testKey === false) {
            return null;
        }

        return $pem;
    }
}
