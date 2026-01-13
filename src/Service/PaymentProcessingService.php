<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PaymentProcessingService
{
    private PaymentProcessor $paymentProcessor;

    public function __construct(PaymentProcessor $paymentProcessor)
    {
        $this->paymentProcessor = $paymentProcessor;
    }

    public function processPayment(Cart $cart, array $paymentData, SalesChannelContext $context): void
    {
        // Extract payment method from handler_id
        $handlerId = $paymentData['handler_id'] ?? null;
        if (!$handlerId) {
            throw new \InvalidArgumentException('Payment handler_id is required');
        }
        
        // Map UCP payment handler to Shopware payment method
        $paymentMethodId = $this->mapHandlerToPaymentMethod($handlerId, $context);
        
        if (!$paymentMethodId) {
            throw new \InvalidArgumentException("Payment handler not found: {$handlerId}");
        }
        
        // Set payment method on cart
        $cart->setPaymentMethodId($paymentMethodId);
        
        // Store payment credential for later processing
        // In production, this would be stored securely and processed during order creation
        $cart->addExtension('ucp_payment_data', $paymentData);
    }

    private function mapHandlerToPaymentMethod(string $handlerId, SalesChannelContext $context): ?string
    {
        // In a real implementation, this would query payment methods
        // and match them to UCP handlers based on configuration
        
        // For now, return first available payment method
        $paymentMethods = $context->getSalesChannel()->getPaymentMethods();
        if ($paymentMethods && $paymentMethods->count() > 0) {
            return $paymentMethods->first()->getId();
        }
        
        return null;
    }
}
