<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class DiscoveryService
{
    private SystemConfigService $systemConfigService;
    private KeyManagementService $keyManagementService;

    public function __construct(
        SystemConfigService $systemConfigService,
        KeyManagementService $keyManagementService
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->keyManagementService = $keyManagementService;
    }

    public function getUcpVersion(string $salesChannelId): string
    {
        $version = $this->systemConfigService->getString(
            'SwagUcp.config.ucpVersion',
            $salesChannelId
        );
        
        return !empty($version) ? $version : '2026-01-11';
    }

    public function getCapabilities(string $salesChannelId): array
    {
        // Base capabilities
        $capabilities = [
            [
                'name' => 'dev.ucp.shopping.checkout',
                'version' => $this->getUcpVersion($salesChannelId),
                'spec' => 'https://ucp.dev/specification/checkout',
                'schema' => 'https://ucp.dev/schemas/shopping/checkout.json'
            ]
        ];

        // Add fulfillment extension if enabled
        // In a real implementation, this would be configurable
        $capabilities[] = [
            'name' => 'dev.ucp.shopping.fulfillment',
            'version' => $this->getUcpVersion($salesChannelId),
            'spec' => 'https://ucp.dev/specification/fulfillment',
            'schema' => 'https://ucp.dev/schemas/shopping/fulfillment.json',
            'extends' => 'dev.ucp.shopping.checkout'
        ];

        return $capabilities;
    }

    public function getSigningKeys(string $salesChannelId): array
    {
        return $this->keyManagementService->getPublicKeys($salesChannelId);
    }
}
