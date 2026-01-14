<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Ucp;

class DiscoveryService
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly KeyManagementService $keyManagementService,
    ) {
    }

    public function getUcpVersion(string $salesChannelId): string
    {
        $version = $this->systemConfigService->getString(Ucp::CONFIG_VERSION, $salesChannelId);

        return $version !== '' ? $version : Ucp::VERSION;
    }

    /**
     * @return list<array<string, string>>
     */
    public function getCapabilities(string $salesChannelId): array
    {
        $version = $this->getUcpVersion($salesChannelId);

        return [
            [
                'name' => Ucp::CAPABILITY_CHECKOUT,
                'version' => $version,
                'spec' => Ucp::SPEC_CHECKOUT,
                'schema' => Ucp::SCHEMA_CHECKOUT,
            ],
            [
                'name' => Ucp::CAPABILITY_FULFILLMENT,
                'version' => $version,
                'spec' => Ucp::SPEC_FULFILLMENT,
                'schema' => Ucp::SCHEMA_FULFILLMENT,
                'extends' => Ucp::CAPABILITY_CHECKOUT,
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function getSigningKeys(string $salesChannelId): array
    {
        return $this->keyManagementService->getPublicKeys($salesChannelId);
    }
}
