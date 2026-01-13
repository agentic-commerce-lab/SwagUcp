<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Entity\UcpCheckoutSession;
use SwagUcp\Repository\UcpCheckoutSessionRepository;

class CheckoutService
{
    private CartService $cartService;
    private UcpCheckoutSessionRepository $sessionRepository;
    private OrderService $orderService;
    private PaymentProcessingService $paymentProcessingService;
    private DiscoveryService $discoveryService;

    public function __construct(
        CartService $cartService,
        UcpCheckoutSessionRepository $sessionRepository,
        OrderService $orderService,
        PaymentProcessingService $paymentProcessingService
    ) {
        $this->cartService = $cartService;
        $this->sessionRepository = $sessionRepository;
        $this->orderService = $orderService;
        $this->paymentProcessingService = $paymentProcessingService;
    }

    public function createSession(array $checkoutData, array $capabilities, SalesChannelContext $context): UcpCheckoutSession
    {
        $sessionId = $this->generateCheckoutId();
        
        $session = new UcpCheckoutSession();
        $session->setId($sessionId);
        $session->setCartToken($context->getToken());
        $session->setStatus('incomplete');
        $session->setCapabilities($capabilities);
        $session->setCheckoutData($checkoutData);
        $session->setExpiresAt(new \DateTime('+30 minutes'));
        $session->setCreatedAt(new \DateTime());
        
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
        if ($session->getExpiresAt() < new \DateTime()) {
            $session->setStatus('canceled');
            $this->sessionRepository->save($session, $context->getContext());
            throw new \RuntimeException("Checkout session expired: {$id}");
        }
        
        return $session;
    }

    public function updateSession(UcpCheckoutSession $session, array $checkoutData, SalesChannelContext $context): void
    {
        // Merge checkout data
        $existingData = $session->getCheckoutData();
        $mergedData = array_merge($existingData, $checkoutData);
        
        $session->setCheckoutData($mergedData);
        
        // Update status based on data completeness
        $status = $this->determineStatus($mergedData, $session->getCapabilities());
        $session->setStatus($status);
        
        $this->sessionRepository->save($session, $context->getContext());
    }

    public function complete(UcpCheckoutSession $session, ?array $paymentData, SalesChannelContext $context): OrderEntity
    {
        if ($session->getStatus() !== 'ready_for_complete') {
            throw new \InvalidArgumentException('Checkout session is not ready for completion');
        }
        
        // Get cart
        $cart = $this->cartService->getCart($session->getCartToken(), $context);
        
        // Process payment if provided
        if ($paymentData) {
            $this->paymentProcessingService->processPayment($cart, $paymentData, $context);
        }
        
        // Create order
        $orderId = $this->orderService->createOrder($cart, $context->getContext());
        
        // Load order entity (simplified - in production use OrderRepository)
        $order = new \Shopware\Core\Checkout\Order\OrderEntity();
        $order->setId($orderId);
        
        // Update session
        $session->setStatus('completed');
        $session->setOrderId($orderId);
        $this->sessionRepository->save($session, $context->getContext());
        
        return $order;
    }

    public function cancel(UcpCheckoutSession $session, SalesChannelContext $context): void
    {
        $session->setStatus('canceled');
        $this->sessionRepository->save($session, $context->getContext());
    }

    public function getBusinessCapabilities(string $salesChannelId): array
    {
        // This would normally use DiscoveryService, but to avoid circular dependency,
        // we'll create a minimal implementation here
        return [
            [
                'name' => 'dev.ucp.shopping.checkout',
                'version' => '2026-01-11'
            ],
            [
                'name' => 'dev.ucp.shopping.fulfillment',
                'version' => '2026-01-11',
                'extends' => 'dev.ucp.shopping.checkout'
            ]
        ];
    }

    private function determineStatus(array $checkoutData, array $capabilities): string
    {
        $hasBuyerEmail = isset($checkoutData['buyer']['email']) && !empty($checkoutData['buyer']['email']);
        
        if (!$hasBuyerEmail) {
            return 'incomplete';
        }
        
        // Check fulfillment if capability is active
        $hasFulfillment = false;
        foreach ($capabilities as $cap) {
            if ($cap['name'] === 'dev.ucp.shopping.fulfillment') {
                $hasFulfillment = isset($checkoutData['fulfillment']['methods']) 
                    && count($checkoutData['fulfillment']['methods']) > 0;
                break;
            }
        }
        
        if ($hasFulfillment && !$hasFulfillment) {
            return 'incomplete';
        }
        
        return 'ready_for_complete';
    }

    private function generateCheckoutId(): string
    {
        return 'chk_' . bin2hex(random_bytes(16));
    }
}
