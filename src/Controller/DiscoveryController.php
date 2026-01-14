<?php

declare(strict_types=1);

namespace SwagUcp\Controller;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagUcp\Service\DiscoveryService;
use SwagUcp\Service\PaymentHandlerService;
use SwagUcp\Ucp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class DiscoveryController
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
        private readonly PaymentHandlerService $paymentHandlerService,
    ) {
    }

    #[Route(path: '/.well-known/ucp', name: 'api.ucp.discovery', methods: ['GET'], defaults: ['auth_required' => false])]
    public function getProfile(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $version = $this->discoveryService->getUcpVersion($salesChannelId);
        $baseUrl = $request->getSchemeAndHttpHost();

        $profile = [
            'ucp' => [
                'version' => $version,
                'services' => [
                    Ucp::CAPABILITY_SHOPPING => [
                        'version' => $version,
                        'spec' => Ucp::SPEC_OVERVIEW,
                        'rest' => [
                            'schema' => Ucp::SERVICE_SHOPPING_REST_SCHEMA,
                            'endpoint' => $baseUrl . '/ucp/checkout-sessions',
                        ],
                        'mcp' => [
                            'schema' => Ucp::SERVICE_SHOPPING_MCP_SCHEMA,
                            'endpoint' => $baseUrl . '/ucp/mcp',
                        ],
                    ],
                ],
                'capabilities' => $this->discoveryService->getCapabilities($salesChannelId),
            ],
            'payment' => [
                'handlers' => $this->paymentHandlerService->getHandlers($context),
            ],
            'signing_keys' => $this->discoveryService->getSigningKeys($salesChannelId),
        ];

        return new JsonResponse($profile);
    }
}
