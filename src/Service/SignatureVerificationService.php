<?php

declare(strict_types=1);

namespace SwagUcp\Service;

/**
 * Signature Verification and Signing Service for UCP.
 * 
 * Implements ES256 (ECDSA P-256 + SHA-256) signatures using:
 * - Standard JWT for internal tokens
 * - Detached JWS (RFC 7797) for webhook Request-Signature headers
 * 
 * @see https://datatracker.ietf.org/doc/html/rfc7515
 * @see https://datatracker.ietf.org/doc/html/rfc7797
 */
class SignatureVerificationService
{
    private KeyManagementService $keyManagementService;

    public function __construct(KeyManagementService $keyManagementService)
    {
        $this->keyManagementService = $keyManagementService;
    }

    /**
     * Verify a Request-Signature header against a request body.
     * 
     * Used by platforms to verify incoming webhooks from businesses.
     * The signature is a Detached JWS where the payload is transmitted
     * separately as the request body.
     * 
     * @param string $signature The Request-Signature header value (detached JWS)
     * @param string $body The request body that was signed
     * @param array $signerPublicKeys Array of JWK public keys from signer's profile
     * @return bool True if signature is valid
     */
    public function verifyRequestSignature(string $signature, string $body, array $signerPublicKeys): bool
    {
        try {
            // Parse detached JWS (header..signature - note double dot)
            $parts = explode('.', $signature);
            
            // Detached JWS has format: header..signature (payload is empty)
            if (count($parts) !== 3 || $parts[1] !== '') {
                // Not a detached JWS, might be a regular JWT
                if (count($parts) !== 3) {
                    return false;
                }
            }

            $headerB64 = $parts[0];
            $signatureB64 = $parts[2];

            // Decode and validate header
            $header = json_decode($this->base64UrlDecode($headerB64), true);
            if (!$header) {
                return false;
            }

            // Verify algorithm is ES256 (required by UCP spec)
            $alg = $header['alg'] ?? '';
            if (!in_array($alg, ['ES256', 'ES384', 'ES512'], true)) {
                return false;
            }

            // Get key ID from header
            $kid = $header['kid'] ?? null;

            // Find matching key
            $publicKey = null;
            foreach ($signerPublicKeys as $key) {
                if ($kid === null || ($key['kid'] ?? '') === $kid) {
                    $publicKey = $key;
                    break;
                }
            }

            if ($publicKey === null) {
                return false;
            }

            // Convert JWK to PEM
            $publicKeyPem = $this->keyManagementService->jwkToPem($publicKey);
            if ($publicKeyPem === null) {
                return false;
            }

            // Reconstruct signing input for detached JWS:
            // header_base64url . "." . payload_base64url
            $payloadB64 = $this->base64UrlEncode($body);
            $signingInput = $headerB64 . '.' . $payloadB64;

            // Decode signature
            $signatureRaw = $this->base64UrlDecode($signatureB64);
            if ($signatureRaw === false) {
                return false;
            }

            // Verify based on algorithm
            return $this->verifyEs256($signingInput, $signatureRaw, $publicKeyPem);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a Request-Signature header for a webhook payload.
     * 
     * Creates a Detached JWS (RFC 7797) where the payload is transmitted
     * separately as the request body, not in the JWS itself.
     * 
     * @param string $body The request body to sign
     * @param string $salesChannelId Sales channel ID for key lookup
     * @return string Detached JWS signature (header..signature format)
     * @throws \RuntimeException If signing fails
     */
    public function createRequestSignature(string $body, string $salesChannelId): string
    {
        $privateKeyPem = $this->keyManagementService->getPrivateKey($salesChannelId);
        if (!$privateKeyPem) {
            throw new \RuntimeException('Private key not configured for signing');
        }

        $keyId = $this->keyManagementService->getKeyId($salesChannelId);

        // Create JWS header with key ID
        $header = [
            'alg' => 'ES256',
            'typ' => 'JWT',
            'kid' => $keyId,
        ];

        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode($body);

        // Signing input is header.payload (per JWS spec)
        $signingInput = $headerB64 . '.' . $payloadB64;

        // Sign
        $signature = $this->signEs256($signingInput, $privateKeyPem);

        // Return detached JWS (header..signature, empty payload section)
        return $headerB64 . '..' . $this->base64UrlEncode($signature);
    }

    /**
     * Create a merchant authorization signature for AP2 mandate support.
     * 
     * Signs the checkout payload (excluding ap2 field) and returns
     * a detached JWS for inclusion in ap2.merchant_authorization.
     * 
     * @param array $checkout Checkout data to sign (ap2 field will be excluded)
     * @param string $salesChannelId Sales channel ID for key lookup
     * @return string Detached JWS signature
     */
    public function signMerchantAuthorization(array $checkout, string $salesChannelId): string
    {
        // Remove ap2 field before signing (per UCP spec)
        $payload = $checkout;
        unset($payload['ap2']);

        // Canonicalize JSON (simple approach - sort keys recursively)
        $canonicalJson = $this->canonicalizeJson($payload);

        return $this->createRequestSignature($canonicalJson, $salesChannelId);
    }

    /**
     * Verify a merchant authorization signature.
     * 
     * @param array $checkout Checkout data with ap2.merchant_authorization
     * @param array $merchantPublicKeys Merchant's public keys from their profile
     * @return bool True if signature is valid
     */
    public function verifyMerchantAuthorization(array $checkout, array $merchantPublicKeys): bool
    {
        if (!isset($checkout['ap2']['merchant_authorization'])) {
            return false;
        }

        $signature = $checkout['ap2']['merchant_authorization'];
        
        // Remove ap2 field to reconstruct signed payload
        $payload = $checkout;
        unset($payload['ap2']);

        $canonicalJson = $this->canonicalizeJson($payload);

        return $this->verifyRequestSignature($signature, $canonicalJson, $merchantPublicKeys);
    }

    /**
     * Verify a standard JWT token signature.
     * 
     * @param string $token JWT token
     * @param string $salesChannelId Sales channel ID for key lookup
     * @return array|null Decoded payload if valid, null otherwise
     */
    public function verifyJwt(string $token, string $salesChannelId): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$headerB64, $payloadB64, $signatureB64] = $parts;

            // Decode header
            $header = json_decode($this->base64UrlDecode($headerB64), true);
            if (!$header || ($header['alg'] ?? '') !== 'ES256') {
                return null;
            }

            // Get our public keys
            $publicKeys = $this->keyManagementService->getPublicKeys($salesChannelId);

            // Try to verify with each key
            foreach ($publicKeys as $keyData) {
                $publicKeyPem = $this->keyManagementService->jwkToPem($keyData);
                if ($publicKeyPem === null) {
                    continue;
                }

                $signingInput = $headerB64 . '.' . $payloadB64;
                $signatureRaw = $this->base64UrlDecode($signatureB64);

                if ($signatureRaw !== false && $this->verifyEs256($signingInput, $signatureRaw, $publicKeyPem)) {
                    // Signature valid, return decoded payload
                    $payload = json_decode($this->base64UrlDecode($payloadB64), true);
                    return is_array($payload) ? $payload : null;
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create a JWT token.
     * 
     * @param array $payload JWT payload
     * @param string $salesChannelId Sales channel ID for key lookup
     * @return string JWT token
     */
    public function createJwt(array $payload, string $salesChannelId): string
    {
        $privateKeyPem = $this->keyManagementService->getPrivateKey($salesChannelId);
        if (!$privateKeyPem) {
            throw new \RuntimeException('Private key not configured');
        }

        $keyId = $this->keyManagementService->getKeyId($salesChannelId);

        $header = [
            'alg' => 'ES256',
            'typ' => 'JWT',
            'kid' => $keyId,
        ];

        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));

        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = $this->signEs256($signingInput, $privateKeyPem);

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Sign data using ES256 (ECDSA P-256 + SHA-256).
     * 
     * @param string $data Data to sign
     * @param string $privateKeyPem Private key in PEM format
     * @return string Raw signature (r || s format, 64 bytes)
     */
    private function signEs256(string $data, string $privateKeyPem): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new \RuntimeException('Invalid private key');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to create signature');
        }

        // Convert DER signature to raw format for ES256/JWT
        return $this->derToRaw($signature);
    }

    /**
     * Verify ES256 signature.
     * 
     * @param string $data Signed data
     * @param string $signatureRaw Raw signature (r || s format)
     * @param string $publicKeyPem Public key in PEM format
     * @return bool True if signature is valid
     */
    private function verifyEs256(string $data, string $signatureRaw, string $publicKeyPem): bool
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        // Convert raw signature to DER format for OpenSSL
        $derSignature = $this->rawToDer($signatureRaw);

        return openssl_verify($data, $derSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Convert DER-encoded ECDSA signature to raw format (r || s).
     * 
     * ES256 uses 64-byte raw signatures (32 bytes r + 32 bytes s).
     * OpenSSL produces DER-encoded signatures that need conversion.
     */
    private function derToRaw(string $der): string
    {
        // DER format: 0x30 <length> 0x02 <r-length> <r> 0x02 <s-length> <s>
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            throw new \RuntimeException('Invalid DER signature format');
        }

        $offset = 2;
        
        // Parse R
        if (ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature: expected integer for R');
        }
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;

        // Parse S
        if (ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature: expected integer for S');
        }
        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        // Remove leading zeros and pad to 32 bytes
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Convert raw signature (r || s) to DER format.
     * 
     * OpenSSL expects DER-encoded signatures for verification.
     */
    private function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 64) {
            // Handle variable-length signatures
            $halfLen = (int)(strlen($raw) / 2);
            $r = substr($raw, 0, $halfLen);
            $s = substr($raw, $halfLen);
        } else {
            $r = substr($raw, 0, 32);
            $s = substr($raw, 32, 32);
        }

        // Remove leading zeros but keep at least one byte
        $r = ltrim($r, "\x00") ?: "\x00";
        $s = ltrim($s, "\x00") ?: "\x00";

        // Add leading zero if high bit is set (to keep positive)
        if (ord($r[0]) & 0x80) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) & 0x80) {
            $s = "\x00" . $s;
        }

        $rLen = strlen($r);
        $sLen = strlen($s);
        $totalLen = 4 + $rLen + $sLen;

        return "\x30" . chr($totalLen) . "\x02" . chr($rLen) . $r . "\x02" . chr($sLen) . $s;
    }

    /**
     * Base64 URL-safe encoding (RFC 7515).
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decoding (RFC 7515).
     */
    private function base64UrlDecode(string $data): string|false
    {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($data);
    }

    /**
     * Canonicalize JSON for consistent signing.
     * 
     * Simple implementation that sorts keys recursively.
     * For full JCS (RFC 8785) compliance, a proper library should be used.
     */
    private function canonicalizeJson(array $data): string
    {
        $sorted = $this->sortArrayRecursive($data);
        return json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sort array keys recursively.
     */
    private function sortArrayRecursive(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->sortArrayRecursive($value);
            }
        }
        ksort($array);
        return $array;
    }
}
