<?php

declare(strict_types=1);

namespace SwagUcp;

/**
 * Central constants for UCP (Universal Commerce Protocol) integration.
 *
 * @see https://ucp.dev/specification/overview/
 */
final class Ucp
{
    /**
     * Default UCP version. Can be overridden via plugin config (SwagUcp.config.ucpVersion).
     * Use DiscoveryService::getUcpVersion() to get the configured version.
     */
    public const VERSION = '2026-01-11';

    // Plugin config keys
    public const CONFIG_VERSION = 'SwagUcp.config.ucpVersion';

    public const BASE_URL = 'https://ucp.dev';

    // Specification URLs
    public const SPEC_OVERVIEW = self::BASE_URL . '/specification/overview';
    public const SPEC_CHECKOUT = self::BASE_URL . '/specification/checkout';
    public const SPEC_FULFILLMENT = self::BASE_URL . '/specification/fulfillment';
    public const SPEC_PAYMENT_HANDLER_GUIDE = self::BASE_URL . '/specification/payment-handler-guide';

    // Schema URLs
    public const SCHEMA_CHECKOUT = self::BASE_URL . '/schemas/shopping/checkout.json';
    public const SCHEMA_FULFILLMENT = self::BASE_URL . '/schemas/shopping/fulfillment.json';
    public const SCHEMA_DELEGATE_PAYMENT = self::BASE_URL . '/schemas/payments/delegate-payment.json';
    public const SCHEMA_CARD_PAYMENT_INSTRUMENT = self::BASE_URL . '/schemas/shopping/types/card_payment_instrument.json';

    // Service Schema URLs
    public const SERVICE_SHOPPING_REST_SCHEMA = self::BASE_URL . '/services/shopping/rest.openapi.json';
    public const SERVICE_SHOPPING_MCP_SCHEMA = self::BASE_URL . '/services/shopping/mcp.openrpc.json';

    // Capability Names
    public const CAPABILITY_SHOPPING = 'dev.ucp.shopping';
    public const CAPABILITY_CHECKOUT = 'dev.ucp.shopping.checkout';
    public const CAPABILITY_FULFILLMENT = 'dev.ucp.shopping.fulfillment';
    public const CAPABILITY_BUSINESS_TOKENIZER = 'dev.ucp.business_tokenizer';

    // Third-party Payment Handler URLs (Google Pay)
    public const GPAY_SPEC = 'https://developers.google.com/merchant/ucp/guides/gpay-payment-handler';
    public const GPAY_CONFIG_SCHEMA = 'https://pay.google.com/gp/p/ucp/' . self::VERSION . '/schemas/gpay_config.json';
    public const GPAY_CARD_INSTRUMENT_SCHEMA = 'https://pay.google.com/gp/p/ucp/' . self::VERSION . '/schemas/gpay_card_payment_instrument.json';
    public const GPAY_HANDLER_NAME = 'com.google.pay';

    /**
     * Check if a capability is present in the capabilities array.
     *
     * @param list<array<string, mixed>> $capabilities
     */
    public static function hasCapability(array $capabilities, string $capabilityName): bool
    {
        foreach ($capabilities as $cap) {
            if (($cap['name'] ?? null) === $capabilityName) {
                return true;
            }
        }

        return false;
    }
}
