<?php

declare(strict_types=1);

namespace SwagUcp\Mapper;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Entity\UcpCheckoutSession;
use SwagUcp\Service\PaymentHandlerService;
use SwagUcp\Ucp;

class CheckoutMapper
{
    public function __construct(
        private readonly PaymentHandlerService $paymentHandlerService,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $capabilities
     *
     * @return array<string, mixed>
     */
    public function mapToUcp(UcpCheckoutSession $session, array $capabilities, SalesChannelContext $context): array
    {
        $checkoutData = $session->checkoutData;

        $ucpCheckout = [
            'id' => $session->id,
            'status' => $session->status,
            'currency' => $context->getCurrency()->getIsoCode(),
            'line_items' => $checkoutData['line_items'] ?? [],
            'totals' => $this->calculateTotals($checkoutData),
        ];

        // Add buyer if available
        if (isset($checkoutData['buyer'])) {
            $ucpCheckout['buyer'] = $checkoutData['buyer'];
        }

        // Add fulfillment if capability is active
        if (Ucp::hasCapability($capabilities, Ucp::CAPABILITY_FULFILLMENT) && isset($checkoutData['fulfillment'])) {
            $ucpCheckout['fulfillment'] = $checkoutData['fulfillment'];
        }

        // Add payment handlers
        $ucpCheckout['payment'] = [
            'handlers' => $this->paymentHandlerService->getHandlers($context),
        ];

        // Add continue_url
        $ucpCheckout['continue_url'] = $this->generateContinueUrl($session, $context);

        // Add links
        $ucpCheckout['links'] = $this->generateLinks($context);

        // Add messages if status is incomplete
        if ($session->status === 'incomplete') {
            $ucpCheckout['messages'] = $this->generateMessages($checkoutData, $capabilities);
        }

        // Add expires_at
        $ucpCheckout['expires_at'] = $session->expiresAt->format('c');

        return $ucpCheckout;
    }

    /**
     * @param array<string, mixed> $checkoutData
     *
     * @return list<array<string, mixed>>
     */
    private function calculateTotals(array $checkoutData): array
    {
        $totals = [];
        $subtotal = 0;

        // Calculate subtotal from line items
        foreach ($checkoutData['line_items'] ?? [] as $lineItem) {
            $itemPrice = $lineItem['item']['price'] ?? 0;
            $quantity = $lineItem['quantity'] ?? 1;
            $subtotal += $itemPrice * $quantity;
        }

        $totals[] = [
            'type' => 'subtotal',
            'amount' => $subtotal,
        ];

        // Add tax (simplified - in production, use Shopware tax calculation)
        $tax = (int) ($subtotal * 0.19); // 19% VAT
        if ($tax > 0) {
            $totals[] = [
                'type' => 'tax',
                'amount' => $tax,
            ];
        }

        // Add shipping if fulfillment is present
        $shippingCost = 0;
        if (isset($checkoutData['fulfillment'])) {
            $shippingCost = $this->calculateShippingCost($checkoutData['fulfillment']);
            if ($shippingCost > 0) {
                $totals[] = [
                    'type' => 'fulfillment',
                    'display_text' => 'Shipping',
                    'amount' => $shippingCost,
                ];
            }
        }

        // Total
        $total = $subtotal + $tax + $shippingCost;
        $totals[] = [
            'type' => 'total',
            'amount' => $total,
        ];

        return $totals;
    }

    /**
     * @param array<string, mixed> $fulfillment
     */
    private function calculateShippingCost(array $fulfillment): int
    {
        if (isset($fulfillment['methods'][0]['groups'][0]['selected_option_id'])) {
            $selectedOption = $fulfillment['methods'][0]['groups'][0]['selected_option_id'];
            if ($selectedOption === 'express') {
                return 1000; // 10.00 in cents
            }
        }

        return 500; // 5.00 in cents (standard)
    }

    private function generateContinueUrl(UcpCheckoutSession $session, SalesChannelContext $context): string
    {
        $baseUrl = $this->getBaseUrl($context);

        return $baseUrl . '/checkout?token=' . $session->cartToken;
    }

    /**
     * @return array<int, array{type: string, url: string}>
     */
    private function generateLinks(SalesChannelContext $context): array
    {
        $baseUrl = $this->getBaseUrl($context);

        return [
            [
                'type' => 'terms_of_service',
                'url' => $baseUrl . '/terms',
            ],
            [
                'type' => 'privacy_policy',
                'url' => $baseUrl . '/privacy',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @param list<array<string, mixed>> $capabilities
     *
     * @return list<array<string, mixed>>
     */
    private function generateMessages(array $checkoutData, array $capabilities): array
    {
        $messages = [];

        // Check for missing buyer email
        if (!isset($checkoutData['buyer']['email']) || empty($checkoutData['buyer']['email'])) {
            $messages[] = [
                'type' => 'error',
                'code' => 'missing',
                'path' => '$.buyer.email',
                'content' => 'Buyer email is required',
                'severity' => 'recoverable',
            ];
        }

        // Check for fulfillment if capability is active
        if (Ucp::hasCapability($capabilities, Ucp::CAPABILITY_FULFILLMENT)
            && (!isset($checkoutData['fulfillment']) || empty($checkoutData['fulfillment']['methods']))) {
            $messages[] = [
                'type' => 'error',
                'code' => 'missing',
                'path' => '$.fulfillment.methods',
                'content' => 'Fulfillment method is required',
                'severity' => 'recoverable',
            ];
        }

        return $messages;
    }

    private function getBaseUrl(SalesChannelContext $context): string
    {
        $domains = $context->getSalesChannel()->getDomains();
        $domain = $domains?->first();

        return $domain?->getUrl() ?? '';
    }
}
