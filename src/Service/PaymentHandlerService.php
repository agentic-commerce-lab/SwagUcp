<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\RouterInterface;

class PaymentHandlerService
{
    private RouterInterface $router;

    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    public function getHandlers(SalesChannelContext $context): array
    {
        // Simplified implementation - return default handler
        // In production, this would query payment methods from the repository
        return [$this->createDefaultBusinessTokenizer($context)];
    }

    private function mapPaymentMethodToHandler($paymentMethod, SalesChannelContext $context): ?array
    {
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        
        // Map Shopware payment handlers to UCP handlers
        // This is a simplified mapping - in production, this would be configurable
        
        if (strpos($handlerIdentifier, 'google') !== false || strpos($handlerIdentifier, 'gpay') !== false) {
            return [
                'id' => 'google_pay_' . $paymentMethod->getId(),
                'name' => 'com.google.pay',
                'version' => '2026-01-11',
                'spec' => 'https://developers.google.com/merchant/ucp/guides/gpay-payment-handler',
                'config_schema' => 'https://pay.google.com/gp/p/ucp/2026-01-11/schemas/gpay_config.json',
                'instrument_schemas' => [
                    'https://pay.google.com/gp/p/ucp/2026-01-11/schemas/gpay_card_payment_instrument.json'
                ],
                'config' => [
                    'merchant_id' => $this->getGooglePayMerchantId($context),
                    'allowed_payment_methods' => [
                        [
                            'type' => 'CARD',
                            'parameters' => [
                                'allowed_card_networks' => ['VISA', 'MASTERCARD']
                            ]
                        ]
                    ]
                ]
            ];
        }
        
        return null;
    }

    private function createDefaultBusinessTokenizer(SalesChannelContext $context): array
    {
        $baseUrl = $context->getSalesChannel()->getDomains()->first()->getUrl();
        
        return [
            'id' => 'shopware_tokenizer_' . $context->getSalesChannel()->getId(),
            'name' => 'dev.ucp.business_tokenizer',
            'version' => '2026-01-11',
            'spec' => 'https://ucp.dev/specification/payment-handler-guide',
            'config_schema' => 'https://ucp.dev/schemas/payments/delegate-payment.json',
            'instrument_schemas' => [
                'https://ucp.dev/schemas/shopping/types/card_payment_instrument.json'
            ],
            'config' => [
                'token_url' => $baseUrl . '/api/ucp/payment/tokenize',
                'public_key' => $this->getPublicKey($context)
            ]
        ];
    }

    private function getGooglePayMerchantId(SalesChannelContext $context): string
    {
        // In production, this would come from configuration
        return 'example_merchant_id';
    }

    private function getPublicKey(SalesChannelContext $context): string
    {
        // In production, this would come from key management
        return 'example_public_key';
    }
}
