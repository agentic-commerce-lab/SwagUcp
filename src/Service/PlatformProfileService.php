<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for fetching and caching platform UCP profiles.
 *
 * Platforms provide their profile at /.well-known/ucp which contains
 * their capabilities, signing keys, and webhook configuration.
 */
class PlatformProfileService
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * @var array<string, array{profile: array<string, mixed>, expires: int}>
     */
    private array $profileCache = [];

    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Fetch and cache a platform's UCP profile.
     *
     * @param string $profileUrl URL to the platform's /.well-known/ucp
     *
     * @return array<string, mixed>|null Platform profile or null on failure
     */
    public function fetchProfile(string $profileUrl): ?array
    {
        // Check cache
        if (isset($this->profileCache[$profileUrl])) {
            $cached = $this->profileCache[$profileUrl];
            if ($cached['expires'] > time()) {
                return $cached['profile'];
            }
        }

        // Validate URL
        if (!$this->isValidProfileUrl($profileUrl)) {
            return null;
        }

        try {
            if ($this->httpClient === null) {
                // Fallback to file_get_contents if no HTTP client
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'method' => 'GET',
                        'header' => [
                            'Accept: application/json',
                            'User-Agent: SwagUcp/1.0',
                        ],
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]);

                $response = @file_get_contents($profileUrl, false, $context);
                if ($response === false) {
                    return null;
                }
            } else {
                $response = $this->httpClient->request('GET', $profileUrl, [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'SwagUcp/1.0',
                    ],
                ])->getContent();
            }

            $profile = json_decode($response, true);
            if (!\is_array($profile)) {
                return null;
            }

            // Validate profile structure
            if (!$this->isValidProfile($profile)) {
                return null;
            }

            // Cache the profile
            $this->profileCache[$profileUrl] = [
                'profile' => $profile,
                'expires' => time() + self::CACHE_TTL,
            ];

            return $profile;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get signing keys from a platform profile.
     *
     * @param string $profileUrl Platform profile URL
     *
     * @return list<array<string, string>> Array of JWK public keys
     */
    public function getSigningKeys(string $profileUrl): array
    {
        $profile = $this->fetchProfile($profileUrl);

        return $profile['signing_keys'] ?? [];
    }

    /**
     * Parse the UCP-Agent header to extract the profile URL.
     *
     * @param string|null $ucpAgentHeader Value of UCP-Agent header
     *
     * @return string|null Profile URL or null if not present
     */
    public function parseUcpAgentHeader(?string $ucpAgentHeader): ?string
    {
        if ($ucpAgentHeader === null || $ucpAgentHeader === '') {
            return null;
        }

        // Parse Dictionary Structured Field syntax: profile="https://..."
        if (preg_match('/profile="([^"]+)"/', $ucpAgentHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Clear the profile cache.
     */
    public function clearCache(): void
    {
        $this->profileCache = [];
    }

    /**
     * Validate that a URL is a valid UCP profile URL.
     */
    private function isValidProfileUrl(string $url): bool
    {
        $parsed = parse_url($url);

        // Must be HTTPS
        if (($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        // Must have a host
        if (empty($parsed['host'])) {
            return false;
        }

        // Should end with /.well-known/ucp (but allow flexibility)
        return true;
    }

    /**
     * Validate that a profile has the required structure.
     *
     * @param array<string, mixed> $profile
     */
    private function isValidProfile(array $profile): bool
    {
        // Must have UCP section
        if (!isset($profile['ucp']) || !\is_array($profile['ucp'])) {
            return false;
        }

        // Must have version
        if (!isset($profile['ucp']['version'])) {
            return false;
        }

        return true;
    }
}
