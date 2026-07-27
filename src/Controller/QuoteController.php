<?php

declare(strict_types=1);

namespace SwagUcp\Controller;

use Shopware\Core\Framework\ShopwareHttpException;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagUcp\Mapper\QuoteMapper;
use SwagUcp\Service\AgentAuthorizationService;
use SwagUcp\Service\QuoteAccessException;
use SwagUcp\Service\QuoteBuyerService;
use SwagUcp\Service\QuoteFeatureService;
use SwagUcp\Service\QuoteService;
use SwagUcp\Service\QuoteTokenAuthenticator;
use SwagUcp\Ucp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Buyer-facing front door for the com.shopware.quote capability.
 *
 * Follows the CheckoutController pattern: agent auth -> resolve buyer ->
 * service call -> map to response. All routes return 404 when SwagCommercial
 * or the Quote Management license is absent (the capability is then also
 * absent from /.well-known/ucp).
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class QuoteController
{
    public function __construct(
        private readonly QuoteFeatureService $quoteFeatureService,
        private readonly AgentAuthorizationService $agentAuthorizationService,
        private readonly QuoteBuyerService $quoteBuyerService,
        private readonly QuoteService $quoteService,
        private readonly QuoteMapper $quoteMapper,
        private readonly QuoteTokenAuthenticator $tokenAuthenticator,
    ) {
    }

    #[Route(path: '/ucp/quotes', name: 'frontend.ucp.quote.create', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function createQuote(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->handle($request, $context, function (array $body, SalesChannelContext $customerContext): JsonResponse {
            $quote = $this->quoteService->create($body, $customerContext);

            return $this->quoteResponse($quote, Response::HTTP_CREATED);
        });
    }

    #[Route(path: '/ucp/quotes/{id}', name: 'frontend.ucp.quote.get', methods: ['GET'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function getQuote(string $id, Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->handle($request, $context, function (array $body, SalesChannelContext $customerContext) use ($id): JsonResponse {
            $quote = $this->quoteService->read($id, $customerContext);

            return $this->quoteResponse($quote);
        });
    }

    #[Route(path: '/ucp/quotes/{id}/counter', name: 'frontend.ucp.quote.counter', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function counterQuote(string $id, Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->handle($request, $context, function (array $body, SalesChannelContext $customerContext) use ($id): JsonResponse {
            $quote = $this->quoteService->counter($id, $body, $customerContext);

            return $this->quoteResponse($quote);
        });
    }

    #[Route(path: '/ucp/quotes/{id}/accept', name: 'frontend.ucp.quote.accept', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function acceptQuote(string $id, Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->handle($request, $context, function (array $body, SalesChannelContext $customerContext) use ($id): JsonResponse {
            $order = $this->quoteService->accept($id, $customerContext);

            return new JsonResponse([
                'quote_id' => $id,
                'state' => 'accepted',
                'order' => [
                    'id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                ],
                'ucp' => ['version' => Ucp::QUOTE_VERSION],
            ]);
        });
    }

    #[Route(path: '/ucp/quotes/{id}/decline', name: 'frontend.ucp.quote.decline', methods: ['POST'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function declineQuote(string $id, Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->handle($request, $context, function (array $body, SalesChannelContext $customerContext) use ($id): JsonResponse {
            $quote = $this->quoteService->decline($id, $body['comment'] ?? null, $customerContext);

            return $this->quoteResponse($quote);
        });
    }

    /**
     * The capability schema is served by the plugin itself so discovery works
     * offline from any central infrastructure.
     */
    #[Route(path: '/ucp/schemas/quote.openapi.json', name: 'frontend.ucp.quote.schema', methods: ['GET'], defaults: ['auth_required' => false, 'XmlHttpRequest' => true])]
    public function getSchema(SalesChannelContext $context): Response
    {
        if (!$this->quoteFeatureService->isAvailable()) {
            return $this->errorResponse('quote_unavailable', 'Quote capability is not available on this shop', Response::HTTP_NOT_FOUND);
        }

        $schema = file_get_contents(__DIR__ . '/../Resources/schemas/quote.openapi.json');

        return new Response($schema, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    /**
     * Shared request pipeline with two authorization mechanisms:
     *
     * OAuth (preferred, when SwagUcpIdentityLinking is installed): a Bearer
     * access token obtained via dev.ucp.common.identity_linking proves both
     * the agent and the customer's consent in one standardized credential -
     * capability available (404) -> token valid (401 invalid_token) -> `quote`
     * scope (403 insufficient_scope) -> customer flagged (403
     * quote_not_enabled_for_buyer).
     *
     * Legacy (signature + buyer claim): capability available (404) -> agent
     * authenticated (403 unauthorized) -> buyer resolved (404 buyer_not_found)
     * -> agent authorized for buyer (403 agent_not_authorized) -> customer
     * flagged (403 quote_not_enabled_for_buyer).
     *
     * @param callable(array<string, mixed>, SalesChannelContext): JsonResponse $operation
     */
    private function handle(Request $request, SalesChannelContext $context, callable $operation): JsonResponse
    {
        if (!$this->quoteFeatureService->isAvailable()) {
            return $this->errorResponse('quote_unavailable', 'Quote capability is not available on this shop', Response::HTTP_NOT_FOUND);
        }

        $body = [];
        $content = $request->getContent();
        if ($content !== '') {
            $body = json_decode($content, true);
            if (json_last_error() !== \JSON_ERROR_NONE || !\is_array($body)) {
                return $this->errorResponse('invalid_json', 'Invalid JSON in request body', Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            return $operation($body, $this->resolveCustomerContext($request, $body, $context));
        } catch (QuoteAccessException $e) {
            return $this->errorResponse($e->getErrorCode(), $e->getMessage(), $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (ShopwareHttpException $e) {
            // Commercial quote errors pass through unchanged (e.g. quote not
            // found -> 404 without confirming existence, expired accept ->
            // CHECKOUT__QUOTE_CANNOT_PLACE_ORDER 400) - they are part of the
            // published contract.
            return $this->errorResponse($e->getErrorCode(), $e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->errorResponse('internal_error', 'An error occurred: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws QuoteAccessException
     */
    private function resolveCustomerContext(Request $request, array $body, SalesChannelContext $context): SalesChannelContext
    {
        $authorizationHeader = (string) $request->headers->get('Authorization', '');

        if (str_starts_with($authorizationHeader, 'Bearer ')) {
            $customerId = $this->tokenAuthenticator->authenticate(substr($authorizationHeader, 7));

            return $this->quoteBuyerService->resolveTokenContext($customerId, $context);
        }

        $ucpAgentHeader = $request->headers->get('UCP-Agent');
        $authResult = $this->agentAuthorizationService->authorizeRequest(
            $ucpAgentHeader,
            $request->headers->get('Request-Signature'),
            $request->getContent(),
            $context->getSalesChannelId()
        );

        if (!$authResult->isAllowed()) {
            throw new QuoteAccessException('unauthorized', Response::HTTP_FORBIDDEN, $authResult->getReason());
        }

        return $this->quoteBuyerService->resolveContext(
            $this->agentAuthorizationService->extractAgentDomain($ucpAgentHeader),
            $body['buyer']['email'] ?? $request->query->get('buyer_email'),
            $body['buyer']['customer_number'] ?? $request->query->get('buyer_customer_number'),
            $context
        );
    }

    private function quoteResponse(object $quote, int $status = Response::HTTP_OK): JsonResponse
    {
        $mapped = $this->quoteMapper->map($quote);
        $mapped['ucp'] = ['version' => Ucp::QUOTE_VERSION];

        return new JsonResponse($mapped, $status);
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
}
