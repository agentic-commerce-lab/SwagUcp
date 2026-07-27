<?php

declare(strict_types=1);

namespace SwagUcp\Controller;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagUcp\Service\DiscoveryService;
use SwagUcp\Service\PaymentHandlerService;
use SwagUcp\Service\QuoteFeatureService;
use SwagUcp\Service\UcpCompatibilityService;
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
        private readonly UcpCompatibilityService $ucpCompatibilityService,
        private readonly QuoteFeatureService $quoteFeatureService,
    ) {
    }

    #[Route(path: '/.well-known/ucp', name: 'api.ucp.discovery', methods: ['GET'], defaults: ['auth_required' => false])]
    public function getProfile(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $version = $this->discoveryService->getUcpVersion($salesChannelId);
        $baseUrl = $request->getSchemeAndHttpHost();

        $capabilities = $this->discoveryService->getCapabilities($salesChannelId);
        $handlers = $this->paymentHandlerService->getHandlers($context);

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
            ],
            'signing_keys' => $this->discoveryService->getSigningKeys($salesChannelId),
        ];

        if ($this->quoteFeatureService->isAvailable()) {
            $quoteSchemaUrl = $baseUrl . '/ucp/schemas/quote.openapi.json';
            $profile['ucp']['services'][Ucp::CAPABILITY_QUOTE] = [
                'version' => Ucp::QUOTE_VERSION,
                'spec' => $quoteSchemaUrl,
                'rest' => [
                    'schema' => $quoteSchemaUrl,
                    'endpoint' => $baseUrl . '/ucp/quotes',
                ],
            ];
        }

        $profile = $this->ucpCompatibilityService->buildProfile($profile, $version, $capabilities, $handlers);

        return new JsonResponse($profile);
    }
}
