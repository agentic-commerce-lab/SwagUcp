<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Ucp;

class PaymentHandlerService
{
    public function __construct(
        private readonly DiscoveryService $discoveryService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHandlers(SalesChannelContext $context): array
    {
        return [$this->createDefaultBusinessTokenizer($context)];
    }

    /**
     * @internal Reserved for future use to map Shopware payment methods to UCP handlers.
     *
     * @return array<string, mixed>|null
     */
    private function mapPaymentMethodToHandler(\Shopware\Core\Checkout\Payment\PaymentMethodEntity $paymentMethod, SalesChannelContext $context): ?array
    {
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        $version = $this->discoveryService->getUcpVersion($context->getSalesChannelId());

        if (str_contains($handlerIdentifier, 'google') || str_contains($handlerIdentifier, 'gpay')) {
            return [
                'id' => 'google_pay_' . $paymentMethod->getId(),
                'name' => Ucp::GPAY_HANDLER_NAME,
                'version' => $version,
                'spec' => Ucp::GPAY_SPEC,
                'config_schema' => Ucp::GPAY_CONFIG_SCHEMA,
                'instrument_schemas' => [Ucp::GPAY_CARD_INSTRUMENT_SCHEMA],
                'config' => [
                    'merchant_id' => $this->getGooglePayMerchantId($context),
                    'allowed_payment_methods' => [
                        [
                            'type' => 'CARD',
                            'parameters' => [
                                'allowed_card_networks' => ['VISA', 'MASTERCARD'],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function createDefaultBusinessTokenizer(SalesChannelContext $context): array
    {
        $domains = $context->getSalesChannel()->getDomains();
        $baseUrl = $domains?->first()?->getUrl() ?? '';
        $version = $this->discoveryService->getUcpVersion($context->getSalesChannelId());

        return [
            'id' => 'shopware_tokenizer_' . $context->getSalesChannel()->getId(),
            'name' => Ucp::CAPABILITY_BUSINESS_TOKENIZER,
            'version' => $version,
            'spec' => Ucp::SPEC_PAYMENT_HANDLER_GUIDE,
            'config_schema' => Ucp::SCHEMA_DELEGATE_PAYMENT,
            'instrument_schemas' => [Ucp::SCHEMA_CARD_PAYMENT_INSTRUMENT],
            'config' => [
                'token_url' => $baseUrl . '/api/ucp/payment/tokenize',
                'public_key' => $this->getPublicKey($context),
            ],
        ];
    }

    private function getGooglePayMerchantId(SalesChannelContext $context): string
    {
        return 'example_merchant_id';
    }

    private function getPublicKey(SalesChannelContext $context): string
    {
        return 'example_public_key';
    }
}
