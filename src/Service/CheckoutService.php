<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Entity\UcpCheckoutSession;
use SwagUcp\Repository\UcpCheckoutSessionRepository;
use SwagUcp\Ucp;

class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly UcpCheckoutSessionRepository $sessionRepository,
        private readonly OrderService $orderService,
        private readonly PaymentProcessingService $paymentProcessingService,
        private readonly DiscoveryService $discoveryService,
    ) {
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @param list<array<string, mixed>> $capabilities
     */
    public function createSession(array $checkoutData, array $capabilities, SalesChannelContext $context): UcpCheckoutSession
    {
        $session = new UcpCheckoutSession(
            id: $this->generateCheckoutId(),
            cartToken: $context->getToken(),
            status: 'incomplete',
            capabilities: $capabilities,
            checkoutData: $checkoutData,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            createdAt: new \DateTimeImmutable(),
        );

        $this->sessionRepository->save($session, $context->getContext());

        return $session;
    }

    public function getSession(string $id, SalesChannelContext $context): UcpCheckoutSession
    {
        $session = $this->sessionRepository->get($id, $context->getContext());

        if (!$session) {
            throw new \RuntimeException("Checkout session not found: {$id}");
        }

        // Check expiration
        if ($session->expiresAt < new \DateTimeImmutable()) {
            $session->status = 'canceled';
            $this->sessionRepository->save($session, $context->getContext());
            throw new \RuntimeException("Checkout session expired: {$id}");
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $checkoutData
     */
    public function updateSession(UcpCheckoutSession $session, array $checkoutData, SalesChannelContext $context): void
    {
        $session->checkoutData = array_merge($session->checkoutData, $checkoutData);
        $session->status = $this->determineStatus($session->checkoutData, $session->capabilities);

        $this->sessionRepository->save($session, $context->getContext());
    }

    /**
     * @param array<string, mixed>|null $paymentData
     */
    public function complete(UcpCheckoutSession $session, ?array $paymentData, SalesChannelContext $context): OrderEntity
    {
        if ($session->status !== 'ready_for_complete') {
            throw new \InvalidArgumentException('Checkout session is not ready for completion');
        }

        // Get cart and process payment if provided
        $cart = $this->cartService->getCart($session->cartToken, $context);

        if ($paymentData) {
            $this->paymentProcessingService->processPayment($cart, $paymentData, $context);
        }

        // Create order using a DataBag (OrderService gets cart from context token internally)
        $dataBag = new RequestDataBag([
            'tos' => true,
        ]);
        $orderId = $this->orderService->createOrder($dataBag, $context);

        // Load order entity (simplified - in production use OrderRepository)
        $order = new OrderEntity();
        $order->setId($orderId);

        // Update session
        $session->status = 'completed';
        $session->orderId = $orderId;
        $this->sessionRepository->save($session, $context->getContext());

        return $order;
    }

    public function cancel(UcpCheckoutSession $session, SalesChannelContext $context): void
    {
        $session->status = 'canceled';
        $this->sessionRepository->save($session, $context->getContext());
    }

    /**
     * Delegates to DiscoveryService (no circular dependency - DiscoveryService doesn't depend on CheckoutService).
     *
     * @return list<array<string, string>>
     */
    public function getBusinessCapabilities(string $salesChannelId): array
    {
        return $this->discoveryService->getCapabilities($salesChannelId);
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @param list<array<string, mixed>> $capabilities
     */
    private function determineStatus(array $checkoutData, array $capabilities): string
    {
        $hasBuyerEmail = isset($checkoutData['buyer']['email']) && !empty($checkoutData['buyer']['email']);

        if (!$hasBuyerEmail) {
            return 'incomplete';
        }

        // Check fulfillment if capability is active
        if (Ucp::hasCapability($capabilities, Ucp::CAPABILITY_FULFILLMENT)) {
            $hasFulfillmentData = isset($checkoutData['fulfillment']['methods'])
                && \count($checkoutData['fulfillment']['methods']) > 0;

            if (!$hasFulfillmentData) {
                return 'incomplete';
            }
        }

        return 'ready_for_complete';
    }

    private function generateCheckoutId(): string
    {
        return 'chk_' . bin2hex(random_bytes(16));
    }
}
