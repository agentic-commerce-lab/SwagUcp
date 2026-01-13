<?php

declare(strict_types=1);

namespace SwagUcp\Controller;

use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Service\DiscoveryService;
use SwagUcp\Service\PaymentHandlerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class DiscoveryController
{
    private DiscoveryService $discoveryService;
    private PaymentHandlerService $paymentHandlerService;
    private RouterInterface $router;

    public function __construct(
        DiscoveryService $discoveryService,
        PaymentHandlerService $paymentHandlerService,
        RouterInterface $router
    ) {
        $this->discoveryService = $discoveryService;
        $this->paymentHandlerService = $paymentHandlerService;
        $this->router = $router;
    }

    #[Route(path: '/.well-known/ucp', name: 'api.ucp.discovery', methods: ['GET'], defaults: ['auth_required' => false])]
    public function getProfile(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannel = $context->getSalesChannel();
        
        $baseUrl = $request->getSchemeAndHttpHost();
        
        $profile = [
            'ucp' => [
                'version' => $this->discoveryService->getUcpVersion($salesChannel->getId()),
                'services' => [
                    'dev.ucp.shopping' => [
                        'version' => $this->discoveryService->getUcpVersion($salesChannel->getId()),
                        'spec' => 'https://ucp.dev/specification/overview',
                        'rest' => [
                            'schema' => 'https://ucp.dev/services/shopping/rest.openapi.json',
                            'endpoint' => $baseUrl . '/ucp/checkout-sessions'
                        ],
                        'mcp' => [
                            'schema' => 'https://ucp.dev/services/shopping/mcp.openrpc.json',
                            'endpoint' => $baseUrl . '/ucp/mcp'
                        ]
                    ]
                ],
                'capabilities' => $this->discoveryService->getCapabilities($salesChannel->getId())
            ],
            'payment' => [
                'handlers' => $this->paymentHandlerService->getHandlers($context)
            ],
            'signing_keys' => $this->discoveryService->getSigningKeys($salesChannel->getId())
        ];

        return new JsonResponse($profile);
    }
}
