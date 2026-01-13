<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Agent Authorization Service
 *
 * Verifies that incoming UCP requests originate from authorized AI agent platforms.
 *
 * Security model:
 * 1. Agent sends UCP-Agent header with profile URL
 * 2. We fetch the agent's profile from their /.well-known/ucp endpoint
 * 3. We verify the request signature using the agent's public keys
 * 4. Optionally, we check against a whitelist of allowed agent platforms
 */
class AgentAuthorizationService
{
    private const CONFIG_WHITELIST_ENABLED = 'SwagUcp.config.agentWhitelistEnabled';
    private const CONFIG_WHITELIST_DOMAINS = 'SwagUcp.config.agentWhitelistDomains';
    private const CONFIG_REQUIRE_SIGNATURE = 'SwagUcp.config.requireAgentSignature';

    /**
     * Known trusted AI agent platform domains.
     *
     * @var list<string>
     */
    private const KNOWN_PLATFORMS = [
        'api.openai.com',
        'generativelanguage.googleapis.com', // Gemini
        'api.anthropic.com',
        'api.cohere.ai',
        'api.mistral.ai',
        'inference.aws.amazon.com', // Bedrock
        'api.together.xyz',
        'api.perplexity.ai',
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly SignatureVerificationService $signatureService,
        private readonly PlatformProfileService $platformProfileService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Authorize an incoming UCP request.
     *
     * @param string $ucpAgentHeader The UCP-Agent header value
     * @param string $requestSignature The Request-Signature header value (optional)
     * @param string $requestBody The raw request body
     * @param string $salesChannelId Sales channel ID
     */
    public function authorizeRequest(
        ?string $ucpAgentHeader,
        ?string $requestSignature,
        string $requestBody,
        string $salesChannelId
    ): AuthorizationResult {
        // Check if authorization is required
        $whitelistEnabled = $this->systemConfigService->getBool(
            self::CONFIG_WHITELIST_ENABLED,
            $salesChannelId
        );

        $requireSignature = $this->systemConfigService->getBool(
            self::CONFIG_REQUIRE_SIGNATURE,
            $salesChannelId
        );

        // If no restrictions configured, allow all
        if (!$whitelistEnabled && !$requireSignature) {
            return AuthorizationResult::allowed('No restrictions configured');
        }

        // Extract profile URL from UCP-Agent header
        $profileUrl = $this->extractProfileUrl($ucpAgentHeader);

        if ($profileUrl === null) {
            if ($whitelistEnabled || $requireSignature) {
                return AuthorizationResult::denied('Missing UCP-Agent header');
            }

            return AuthorizationResult::allowed('No profile required');
        }

        // Check whitelist
        if ($whitelistEnabled) {
            $whitelistResult = $this->checkWhitelist($profileUrl, $salesChannelId);
            if (!$whitelistResult->isAllowed()) {
                return $whitelistResult;
            }
        }

        // Verify signature if required
        if ($requireSignature) {
            if (empty($requestSignature)) {
                return AuthorizationResult::denied('Missing Request-Signature header');
            }

            $signatureResult = $this->verifyAgentSignature(
                $profileUrl,
                $requestSignature,
                $requestBody
            );

            if (!$signatureResult->isAllowed()) {
                return $signatureResult;
            }
        }

        return AuthorizationResult::allowed('Agent authorized');
    }

    /**
     * Get list of known trusted platforms.
     *
     * @return list<string>
     */
    public static function getKnownPlatforms(): array
    {
        return self::KNOWN_PLATFORMS;
    }

    /**
     * Check if the agent's domain is in the whitelist.
     */
    private function checkWhitelist(string $profileUrl, string $salesChannelId): AuthorizationResult
    {
        $parsedUrl = parse_url($profileUrl);
        $domain = $parsedUrl['host'] ?? '';

        if (empty($domain)) {
            return AuthorizationResult::denied('Invalid profile URL');
        }

        // Get configured whitelist
        $configuredDomains = $this->systemConfigService->getString(
            self::CONFIG_WHITELIST_DOMAINS,
            $salesChannelId
        );

        $whitelist = array_filter(array_map('trim', explode("\n", $configuredDomains)));

        // Add known platforms if whitelist is empty (default allow known platforms)
        if (empty($whitelist)) {
            $whitelist = self::KNOWN_PLATFORMS;
        }

        // Check if domain matches whitelist
        foreach ($whitelist as $allowedDomain) {
            if ($this->domainMatches($domain, $allowedDomain)) {
                $this->log('info', 'Agent domain whitelisted', ['domain' => $domain]);

                return AuthorizationResult::allowed('Domain whitelisted: ' . $domain);
            }
        }

        $this->log('warning', 'Agent domain not in whitelist', [
            'domain' => $domain,
            'whitelist' => $whitelist,
        ]);

        return AuthorizationResult::denied('Domain not in whitelist: ' . $domain);
    }

    /**
     * Verify the agent's request signature.
     */
    private function verifyAgentSignature(
        string $profileUrl,
        string $signature,
        string $body
    ): AuthorizationResult {
        try {
            // Fetch the agent's profile to get their public keys
            $profile = $this->platformProfileService->fetchProfile($profileUrl);

            if ($profile === null) {
                return AuthorizationResult::denied('Could not fetch agent profile');
            }

            $signingKeys = $profile['signing_keys'] ?? [];

            if (empty($signingKeys)) {
                return AuthorizationResult::denied('No signing keys in agent profile');
            }

            // Verify the signature
            $isValid = $this->signatureService->verifyRequestSignature(
                $signature,
                $body,
                $signingKeys
            );

            if ($isValid) {
                $this->log('info', 'Agent signature verified', ['profile' => $profileUrl]);

                return AuthorizationResult::allowed('Signature verified');
            }

            $this->log('warning', 'Agent signature verification failed', ['profile' => $profileUrl]);

            return AuthorizationResult::denied('Invalid request signature');
        } catch (\Exception $e) {
            $this->log('error', 'Signature verification error', [
                'profile' => $profileUrl,
                'error' => $e->getMessage(),
            ]);

            return AuthorizationResult::denied('Signature verification error: ' . $e->getMessage());
        }
    }

    /**
     * Extract profile URL from UCP-Agent header.
     *
     * Format: UCP/2026-01-11 profile="https://agent.example.com/.well-known/ucp"
     */
    private function extractProfileUrl(?string $header): ?string
    {
        if (empty($header)) {
            return null;
        }

        // Parse Dictionary Structured Field syntax
        if (preg_match('/profile="([^"]+)"/', $header, $matches)) {
            return $matches[1];
        }

        // Try to extract base URL if just a domain is provided
        if (filter_var($header, \FILTER_VALIDATE_URL)) {
            return rtrim($header, '/') . '/.well-known/ucp';
        }

        return null;
    }

    /**
     * Check if a domain matches an allowed pattern.
     * Supports wildcards: *.openai.com matches api.openai.com
     */
    private function domainMatches(string $domain, string $pattern): bool
    {
        $domain = strtolower($domain);
        $pattern = strtolower($pattern);

        // Exact match
        if ($domain === $pattern) {
            return true;
        }

        // Wildcard match
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // Remove *

            return str_ends_with($domain, $suffix);
        }

        // Subdomain match (api.openai.com matches openai.com)
        return str_ends_with($domain, '.' . $pattern);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        match ($level) {
            'debug' => $this->logger->debug('[UCP Agent Auth] ' . $message, $context),
            'info' => $this->logger->info('[UCP Agent Auth] ' . $message, $context),
            'notice' => $this->logger->notice('[UCP Agent Auth] ' . $message, $context),
            'warning' => $this->logger->warning('[UCP Agent Auth] ' . $message, $context),
            'error' => $this->logger->error('[UCP Agent Auth] ' . $message, $context),
            'critical' => $this->logger->critical('[UCP Agent Auth] ' . $message, $context),
            'alert' => $this->logger->alert('[UCP Agent Auth] ' . $message, $context),
            'emergency' => $this->logger->emergency('[UCP Agent Auth] ' . $message, $context),
            default => $this->logger->info('[UCP Agent Auth] ' . $message, $context),
        };
    }
}
