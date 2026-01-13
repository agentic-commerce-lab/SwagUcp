<?php

declare(strict_types=1);

namespace SwagUcp\Service;

/**
 * Trait providing Base64 URL-safe encoding/decoding (RFC 7515).
 */
trait Base64UrlTrait
{
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $data = strtr($data, '-_', '+/');
        $padding = \strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($data, true);
    }
}
