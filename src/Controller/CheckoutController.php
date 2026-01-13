<?php

declare(strict_types=1);

namespace SwagUcp\Controller;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagUcp\Mapper\CheckoutMapper;
use SwagUcp\Service\AgentAuthorizationService;
use SwagUcp\Service\CapabilityNegotiationService;
use SwagUcp\Service\CheckoutService;
use SwagUcp\Service\DiscoveryService;
use SwagUcp\Service\SchemaValidationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class CheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CapabilityNegotiationService $capabilityService,
        private readonly SchemaValidationService $schemaValidationService,
        private readonly CheckoutMapper $checkoutMapper,
        private readonly AgentAuthorizationService $agentAuthorizationService,
        private readonly DiscoveryService $discoveryService,
    ) {
    }

    #[Route(path: '/ucp/checkout-sessions', name: 'frontend.ucp.checkout.create', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function createCheckout(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            // Authorize the agent
            $authResult = $this->authorizeAgent($request, $context);
            if (!$authResult->isAllowed()) {
                return $this->errorResponse('unauthorized', $authResult->getReason(), Response::HTTP_FORBIDDEN);
            }

            // Extract platform profile from UCP-Agent header
            $platformProfile = $this->extractPlatformProfile($request, $context->getSalesChannelId());

            // Negotiate capabilities
            $businessCapabilities = $this->checkoutService->getBusinessCapabilities($context->getSalesChannel()->getId());
            $activeCapabilities = $this->capabilityService->negotiate($platformProfile, $businessCapabilities);

            // Parse and validate request
            $checkoutData = json_decode($request->getContent(), true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                return $this->errorResponse('invalid_json', 'Invalid JSON in request body', Response::HTTP_BAD_REQUEST);
            }

            // Validate against UCP schema
            $this->schemaValidationService->validate('checkout.create', $checkoutData, $activeCapabilities);

            // Create checkout session
            $checkoutSession = $this->checkoutService->createSession($checkoutData, $activeCapabilities, $context);

            // Map to UCP format
            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $activeCapabilities, $context);

            // Add UCP metadata
            $ucpCheckout['ucp'] = [
                'version' => $this->discoveryService->getUcpVersion($context->getSalesChannelId()),
                'capabilities' => $activeCapabilities,
            ];

            return new JsonResponse($ucpCheckout, Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->errorResponse('internal_error', 'An error occurred: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/ucp/checkout-sessions/{id}', name: 'frontend.ucp.checkout.get', methods: ['GET'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function getCheckout(string $id, SalesChannelContext $context): JsonResponse
    {
        try {
            $checkoutSession = $this->checkoutService->getSession($id, $context);

            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->capabilities, $context);
            $ucpCheckout['ucp'] = [
                'version' => $this->discoveryService->getUcpVersion($context->getSalesChannelId()),
                'capabilities' => $checkoutSession->capabilities,
            ];

            return new JsonResponse($ucpCheckout);
        } catch (\RuntimeException $e) {
            return $this->errorResponse('not_found', 'Checkout session not found', Response::HTTP_NOT_FOUND);
        }
    }

    #[Route(path: '/ucp/checkout-sessions/{id}', name: 'frontend.ucp.checkout.update', methods: ['PUT'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function updateCheckout(string $id, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $checkoutSession = $this->checkoutService->getSession($id, $context);

            $checkoutData = json_decode($request->getContent(), true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                return $this->errorResponse('invalid_json', 'Invalid JSON in request body', Response::HTTP_BAD_REQUEST);
            }

            // Validate against schema
            $this->schemaValidationService->validate('checkout.update', $checkoutData, $checkoutSession->capabilities);

            // Update session
            $this->checkoutService->updateSession($checkoutSession, $checkoutData, $context);

            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->capabilities, $context);
            $ucpCheckout['ucp'] = [
                'version' => $this->discoveryService->getUcpVersion($context->getSalesChannelId()),
                'capabilities' => $checkoutSession->capabilities,
            ];

            return new JsonResponse($ucpCheckout);
        } catch (\RuntimeException $e) {
            return $this->errorResponse('not_found', 'Checkout session not found', Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(path: '/ucp/checkout-sessions/{id}/complete', name: 'frontend.ucp.checkout.complete', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function completeCheckout(string $id, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $checkoutSession = $this->checkoutService->getSession($id, $context);

            $completeData = json_decode($request->getContent(), true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                return $this->errorResponse('invalid_json', 'Invalid JSON in request body', Response::HTTP_BAD_REQUEST);
            }

            $paymentData = $completeData['payment_data'] ?? null;

            // Complete checkout and create order
            $order = $this->checkoutService->complete($checkoutSession, $paymentData, $context);

            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->capabilities, $context);
            $ucpCheckout['status'] = 'completed';
            $ucpCheckout['order'] = [
                'id' => $order->getId(),
                'permalink_url' => $this->generateOrderUrl($order, $context),
            ];
            $ucpCheckout['ucp'] = [
                'version' => $this->discoveryService->getUcpVersion($context->getSalesChannelId()),
                'capabilities' => $checkoutSession->capabilities,
            ];

            return new JsonResponse($ucpCheckout);
        } catch (\RuntimeException $e) {
            return $this->errorResponse('not_found', 'Checkout session not found', Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(path: '/ucp/checkout-sessions/{id}/cancel', name: 'frontend.ucp.checkout.cancel', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function cancelCheckout(string $id, SalesChannelContext $context): JsonResponse
    {
        try {
            $checkoutSession = $this->checkoutService->getSession($id, $context);
            $this->checkoutService->cancel($checkoutSession, $context);

            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->capabilities, $context);
            $ucpCheckout['ucp'] = [
                'version' => $this->discoveryService->getUcpVersion($context->getSalesChannelId()),
                'capabilities' => $checkoutSession->capabilities,
            ];

            return new JsonResponse($ucpCheckout);
        } catch (\RuntimeException $e) {
            return $this->errorResponse('not_found', 'Checkout session not found', Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Authorize the incoming agent request.
     */
    private function authorizeAgent(Request $request, SalesChannelContext $context): \SwagUcp\Service\AuthorizationResult
    {
        return $this->agentAuthorizationService->authorizeRequest(
            $request->headers->get('UCP-Agent'),
            $request->headers->get('Request-Signature'),
            $request->getContent(),
            $context->getSalesChannelId()
        );
    }

    /**
     * Extract platform profile from UCP-Agent header.
     *
     * @return array<string, mixed>
     */
    private function extractPlatformProfile(Request $request, string $salesChannelId): array
    {
        $version = $this->discoveryService->getUcpVersion($salesChannelId);

        // TODO: In production, parse profile URL from UCP-Agent header and fetch/cache the profile
        // $ucpAgentHeader = $request->headers->get('UCP-Agent');
        // if ($ucpAgentHeader && preg_match('/profile="([^"]+)"/', $ucpAgentHeader, $matches)) {
        //     $profileUrl = $matches[1];
        //     return $this->platformProfileService->fetchProfile($profileUrl);
        // }

        return ['ucp' => ['version' => $version, 'capabilities' => []]];
    }

    private function errorResponse(string $code, string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'messages' => [
                [
                    'type' => 'error',
                    'code' => $code,
                    'content' => $message,
                    'severity' => 'recoverable',
                ],
            ],
        ], $statusCode);
    }

    private function generateOrderUrl(OrderEntity $order, SalesChannelContext $context): string
    {
        $domains = $context->getSalesChannel()->getDomains();
        $domain = $domains?->first();
        $baseUrl = $domain?->getUrl() ?? '';

        return \sprintf('%s/account/order/%s', $baseUrl, $order->getId());
    }
}
