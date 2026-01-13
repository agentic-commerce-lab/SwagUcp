<?php

declare(strict_types=1);

namespace SwagUcp\Controller;

use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagUcp\Mapper\CheckoutMapper;
use SwagUcp\Service\AgentAuthorizationService;
use SwagUcp\Service\CapabilityNegotiationService;
use SwagUcp\Service\CheckoutService;
use SwagUcp\Service\SchemaValidationService;
use SwagUcp\Service\SignatureVerificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class CheckoutController
{
    private CheckoutService $checkoutService;
    private CapabilityNegotiationService $capabilityService;
    private SchemaValidationService $schemaValidationService;
    private CheckoutMapper $checkoutMapper;
    private SignatureVerificationService $signatureVerificationService;
    private AgentAuthorizationService $agentAuthorizationService;

    public function __construct(
        CheckoutService $checkoutService,
        CapabilityNegotiationService $capabilityService,
        SchemaValidationService $schemaValidationService,
        CheckoutMapper $checkoutMapper,
        SignatureVerificationService $signatureVerificationService,
        AgentAuthorizationService $agentAuthorizationService
    ) {
        $this->checkoutService = $checkoutService;
        $this->capabilityService = $capabilityService;
        $this->schemaValidationService = $schemaValidationService;
        $this->checkoutMapper = $checkoutMapper;
        $this->signatureVerificationService = $signatureVerificationService;
        $this->agentAuthorizationService = $agentAuthorizationService;
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
            $platformProfile = $this->extractPlatformProfile($request);
            
            // Negotiate capabilities
            $businessCapabilities = $this->checkoutService->getBusinessCapabilities($context->getSalesChannel()->getId());
            $activeCapabilities = $this->capabilityService->negotiate($platformProfile, $businessCapabilities);
            
            // Parse and validate request
            $checkoutData = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
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
                'version' => '2026-01-11',
                'capabilities' => $activeCapabilities
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
            
            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->getCapabilities(), $context);
            $ucpCheckout['ucp'] = [
                'version' => '2026-01-11',
                'capabilities' => $checkoutSession->getCapabilities()
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
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse('invalid_json', 'Invalid JSON in request body', Response::HTTP_BAD_REQUEST);
            }
            
            // Validate against schema
            $this->schemaValidationService->validate('checkout.update', $checkoutData, $checkoutSession->getCapabilities());
            
            // Update session
            $this->checkoutService->updateSession($checkoutSession, $checkoutData, $context);
            
            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->getCapabilities(), $context);
            $ucpCheckout['ucp'] = [
                'version' => '2026-01-11',
                'capabilities' => $checkoutSession->getCapabilities()
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
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse('invalid_json', 'Invalid JSON in request body', Response::HTTP_BAD_REQUEST);
            }
            
            $paymentData = $completeData['payment_data'] ?? null;
            
            // Complete checkout and create order
            $order = $this->checkoutService->complete($checkoutSession, $paymentData, $context);
            
            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->getCapabilities(), $context);
            $ucpCheckout['status'] = 'completed';
            $ucpCheckout['order'] = [
                'id' => $order->getId(),
                'permalink_url' => $this->generateOrderUrl($order, $context)
            ];
            $ucpCheckout['ucp'] = [
                'version' => '2026-01-11',
                'capabilities' => $checkoutSession->getCapabilities()
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
            
            $ucpCheckout = $this->checkoutMapper->mapToUcp($checkoutSession, $checkoutSession->getCapabilities(), $context);
            $ucpCheckout['ucp'] = [
                'version' => '2026-01-11',
                'capabilities' => $checkoutSession->getCapabilities()
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

    private function extractPlatformProfile(Request $request): array
    {
        $ucpAgentHeader = $request->headers->get('UCP-Agent');
        if (!$ucpAgentHeader) {
            // Return minimal profile if not provided
            return ['ucp' => ['version' => '2026-01-11', 'capabilities' => []]];
        }
        
        // Parse Dictionary Structured Field syntax: profile="https://..."
        if (preg_match('/profile="([^"]+)"/', $ucpAgentHeader, $matches)) {
            $profileUrl = $matches[1];
            // In production, fetch and cache the profile
            // For now, return minimal profile
            return ['ucp' => ['version' => '2026-01-11', 'capabilities' => []]];
        }
        
        return ['ucp' => ['version' => '2026-01-11', 'capabilities' => []]];
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
                    'severity' => 'recoverable'
                ]
            ]
        ], $statusCode);
    }

    private function generateOrderUrl($order, SalesChannelContext $context): string
    {
        // Generate order detail URL
        return sprintf(
            '%s/account/order/%s',
            $context->getSalesChannel()->getDomains()->first()->getUrl(),
            $order->getId()
        );
    }
}
