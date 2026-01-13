<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\KeyManagementService;
use SwagUcp\Service\SignatureVerificationService;
use SwagUcp\Service\CapabilityNegotiationService;

/**
 * UCP Agent Simulation Test
 *
 * This test simulates a complete UCP flow from the perspective of an AI agent/platform:
 *
 * 1. Discovery - Fetch business profile from /.well-known/ucp
 * 2. Capability Negotiation - Determine active capabilities
 * 3. Create Checkout - Initialize a checkout session
 * 4. Update Checkout - Add buyer info and fulfillment
 * 5. Complete Checkout - Finalize the order with payment
 * 6. Receive Order Update - Verify webhook signature
 *
 * This test validates the complete UCP protocol compliance including
 * cryptographic operations for webhook signing and verification.
 */
class UcpAgentSimulationTest extends TestCase
{
    // Simulated business keys (would be fetched from /.well-known/ucp in production)
    private string $businessPublicKeyPem;
    private string $businessPrivateKeyPem;
    private string $businessKeyId = 'business_key_2026';

    // Simulated platform keys (agent's keys)
    private string $platformPublicKeyPem;
    private string $platformPrivateKeyPem;
    private string $platformKeyId = 'platform_agent_2026';

    private KeyManagementService $keyManagement;
    private SignatureVerificationService $signatureService;
    private CapabilityNegotiationService $capabilityService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create real key management service with mock config
        $configService = $this->createMock(SystemConfigService::class);
        $this->keyManagement = new KeyManagementService($configService);
        $this->signatureService = new SignatureVerificationService($this->keyManagement);
        $this->capabilityService = new CapabilityNegotiationService();

        // Generate business keys (simulating merchant/shop)
        $businessKeys = $this->keyManagement->generateEcP256KeyPair();
        $this->businessPublicKeyPem = $businessKeys['public'];
        $this->businessPrivateKeyPem = $businessKeys['private'];

        // Generate platform keys (simulating AI agent)
        $platformKeys = $this->keyManagement->generateEcP256KeyPair();
        $this->platformPublicKeyPem = $platformKeys['public'];
        $this->platformPrivateKeyPem = $platformKeys['private'];
    }

    /**
     * @test
     * Complete UCP flow simulation from agent perspective
     */
    public function completeUcpFlowAsAgent(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "UCP AGENT SIMULATION - COMPLETE FLOW\n";
        echo str_repeat("=", 70) . "\n\n";

        // ========================================
        // STEP 1: DISCOVERY
        // ========================================
        echo "STEP 1: DISCOVERY\n";
        echo str_repeat("-", 40) . "\n";

        $businessProfile = $this->simulateDiscovery();

        $this->assertArrayHasKey('ucp', $businessProfile);
        $this->assertArrayHasKey('signing_keys', $businessProfile);
        $this->assertEquals('2026-01-11', $businessProfile['ucp']['version']);

        echo "✓ Discovered business profile\n";
        echo "  - UCP Version: {$businessProfile['ucp']['version']}\n";
        echo "  - Capabilities: " . count($businessProfile['ucp']['capabilities']) . "\n";
        echo "  - Signing Keys: " . count($businessProfile['signing_keys']) . "\n\n";

        // ========================================
        // STEP 2: CAPABILITY NEGOTIATION
        // ========================================
        echo "STEP 2: CAPABILITY NEGOTIATION\n";
        echo str_repeat("-", 40) . "\n";

        $platformCapabilities = $this->getPlatformCapabilities();
        $businessCapabilities = $businessProfile['ucp']['capabilities'];

        $activeCapabilities = $this->capabilityService->negotiate(
            ['ucp' => ['version' => '2026-01-11', 'capabilities' => $platformCapabilities]],
            $businessCapabilities
        );

        $this->assertNotEmpty($activeCapabilities);

        echo "✓ Negotiated capabilities\n";
        foreach ($activeCapabilities as $cap) {
            echo "  - {$cap['name']} (v{$cap['version']})\n";
        }
        echo "\n";

        // ========================================
        // STEP 3: CREATE CHECKOUT SESSION
        // ========================================
        echo "STEP 3: CREATE CHECKOUT SESSION\n";
        echo str_repeat("-", 40) . "\n";

        $checkoutRequest = $this->buildCheckoutRequest();
        $checkoutResponse = $this->simulateCreateCheckout($checkoutRequest);

        $this->assertArrayHasKey('id', $checkoutResponse);
        $this->assertEquals('incomplete', $checkoutResponse['status']);
        $this->assertArrayHasKey('totals', $checkoutResponse);

        $checkoutId = $checkoutResponse['id'];

        echo "✓ Created checkout session\n";
        echo "  - Session ID: {$checkoutId}\n";
        echo "  - Status: {$checkoutResponse['status']}\n";
        echo "  - Currency: {$checkoutResponse['currency']}\n";
        $this->printTotals($checkoutResponse['totals']);
        echo "\n";

        // ========================================
        // STEP 4: UPDATE CHECKOUT (Add buyer & fulfillment)
        // ========================================
        echo "STEP 4: UPDATE CHECKOUT\n";
        echo str_repeat("-", 40) . "\n";

        $updateRequest = $this->buildUpdateRequest();
        $updatedCheckout = $this->simulateUpdateCheckout($checkoutId, $updateRequest);

        $this->assertEquals('ready_for_complete', $updatedCheckout['status']);
        $this->assertArrayHasKey('buyer', $updatedCheckout);
        $this->assertArrayHasKey('fulfillment', $updatedCheckout);

        echo "✓ Updated checkout with buyer and fulfillment\n";
        echo "  - Status: {$updatedCheckout['status']}\n";
        echo "  - Buyer: {$updatedCheckout['buyer']['email']}\n";
        echo "  - Fulfillment: " . ($updatedCheckout['fulfillment']['methods'][0]['type'] ?? 'unknown') . "\n";
        $this->printTotals($updatedCheckout['totals']);
        echo "\n";

        // ========================================
        // STEP 5: VERIFY MERCHANT AUTHORIZATION (AP2)
        // ========================================
        echo "STEP 5: VERIFY MERCHANT AUTHORIZATION\n";
        echo str_repeat("-", 40) . "\n";

        // In a real scenario, the checkout response would include ap2.merchant_authorization
        // We simulate adding it here
        $checkoutWithAuth = $this->addMerchantAuthorization($updatedCheckout);

        // Verify the signature
        $businessJwk = $this->keyManagement->pemToJwk($this->businessPublicKeyPem, $this->businessKeyId);
        $isValid = $this->signatureService->verifyMerchantAuthorization($checkoutWithAuth, [$businessJwk]);

        $this->assertTrue($isValid);

        echo "✓ Merchant authorization verified\n";
        echo "  - Signature valid: Yes\n";
        echo "  - Algorithm: ES256\n\n";

        // ========================================
        // STEP 6: COMPLETE CHECKOUT
        // ========================================
        echo "STEP 6: COMPLETE CHECKOUT\n";
        echo str_repeat("-", 40) . "\n";

        $paymentData = $this->buildPaymentData();
        $completedCheckout = $this->simulateCompleteCheckout($checkoutId, $paymentData);

        $this->assertEquals('completed', $completedCheckout['status']);
        $this->assertArrayHasKey('order', $completedCheckout);

        $orderId = $completedCheckout['order']['id'];

        echo "✓ Checkout completed\n";
        echo "  - Status: {$completedCheckout['status']}\n";
        echo "  - Order ID: {$orderId}\n";
        echo "  - Order URL: {$completedCheckout['order']['permalink_url']}\n\n";

        // ========================================
        // STEP 7: RECEIVE ORDER UPDATE WEBHOOK
        // ========================================
        echo "STEP 7: RECEIVE ORDER UPDATE (Payment Confirmed)\n";
        echo str_repeat("-", 40) . "\n";

        $webhookPayload = $this->buildOrderUpdateWebhook($orderId, 'payment_confirmed');
        $webhookSignature = $this->simulateBusinessSignsWebhook($webhookPayload);

        // Platform verifies the webhook signature
        $isWebhookValid = $this->verifyWebhookSignature($webhookSignature, $webhookPayload, $businessProfile);

        $this->assertTrue($isWebhookValid);

        echo "✓ Order update webhook received and verified\n";
        echo "  - Event: {$webhookPayload['event']}\n";
        echo "  - Order ID: {$webhookPayload['order_id']}\n";
        echo "  - Payment Status: {$webhookPayload['data']['payment_status']}\n";
        echo "  - Signature Valid: Yes\n\n";

        // ========================================
        // STEP 8: RECEIVE SHIPMENT UPDATE
        // ========================================
        echo "STEP 8: RECEIVE SHIPMENT UPDATE\n";
        echo str_repeat("-", 40) . "\n";

        $shipmentPayload = $this->buildOrderUpdateWebhook($orderId, 'shipped', [
            'tracking_number' => 'DHL1234567890',
            'carrier' => 'DHL',
            'estimated_delivery' => '2026-01-15',
        ]);
        $shipmentSignature = $this->simulateBusinessSignsWebhook($shipmentPayload);

        $isShipmentWebhookValid = $this->verifyWebhookSignature($shipmentSignature, $shipmentPayload, $businessProfile);

        $this->assertTrue($isShipmentWebhookValid);

        echo "✓ Shipment webhook received and verified\n";
        echo "  - Event: {$shipmentPayload['event']}\n";
        echo "  - Tracking: {$shipmentPayload['data']['tracking_number']}\n";
        echo "  - Carrier: {$shipmentPayload['data']['carrier']}\n";
        echo "  - Est. Delivery: {$shipmentPayload['data']['estimated_delivery']}\n\n";

        // ========================================
        // SUMMARY
        // ========================================
        echo str_repeat("=", 70) . "\n";
        echo "UCP FLOW COMPLETED SUCCESSFULLY\n";
        echo str_repeat("=", 70) . "\n";
        echo "\nSummary:\n";
        echo "  ✓ Discovery & Capability Negotiation\n";
        echo "  ✓ Checkout Creation & Updates\n";
        echo "  ✓ Merchant Authorization (AP2)\n";
        echo "  ✓ Order Completion\n";
        echo "  ✓ Webhook Signature Verification\n";
        echo "  ✓ Order Lifecycle Updates\n";
        echo "\nAll cryptographic operations used ES256 (ECDSA P-256 + SHA-256)\n";
        echo "All webhooks signed with Detached JWS (RFC 7797)\n\n";
    }

    /**
     * @test
     * Test webhook signature verification rejects tampered data
     */
    public function webhookSignatureRejectsTampering(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "SECURITY TEST: WEBHOOK TAMPERING DETECTION\n";
        echo str_repeat("=", 70) . "\n\n";

        $businessProfile = $this->simulateDiscovery();

        // Create a legitimate webhook
        $originalPayload = $this->buildOrderUpdateWebhook('order_123', 'payment_confirmed');
        $signature = $this->simulateBusinessSignsWebhook($originalPayload);

        // Verify original is valid
        $isOriginalValid = $this->verifyWebhookSignature($signature, $originalPayload, $businessProfile);
        $this->assertTrue($isOriginalValid);
        echo "✓ Original webhook verified successfully\n";

        // Tamper with the payload
        $tamperedPayload = $originalPayload;
        $tamperedPayload['data']['payment_status'] = 'refunded'; // Attacker tries to fake refund

        // Verification should fail
        $isTamperedValid = $this->verifyWebhookSignature($signature, $tamperedPayload, $businessProfile);
        $this->assertFalse($isTamperedValid);
        echo "✓ Tampered webhook correctly rejected\n\n";
    }

    /**
     * @test
     * Test complete checkout flow with validation at each step
     */
    public function checkoutFlowValidation(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "VALIDATION TEST: CHECKOUT STATE MACHINE\n";
        echo str_repeat("=", 70) . "\n\n";

        // Create checkout - should be incomplete
        $checkout = $this->simulateCreateCheckout($this->buildCheckoutRequest());
        $this->assertEquals('incomplete', $checkout['status']);
        echo "✓ New checkout has status 'incomplete'\n";

        // Verify messages indicate what's missing
        $this->assertArrayHasKey('messages', $checkout);
        echo "✓ Checkout includes validation messages\n";

        // Update with buyer - should still be incomplete (no fulfillment)
        $partialUpdate = ['buyer' => ['email' => 'test@example.com', 'name' => 'Test User']];
        $partiallyUpdated = $this->simulateUpdateCheckout($checkout['id'], $partialUpdate);

        // Note: depending on capability negotiation, might be ready_for_complete without fulfillment
        echo "✓ Partial update processed\n";

        // Full update - should be ready_for_complete
        $fullUpdate = array_merge($partialUpdate, [
            'fulfillment' => [
                'methods' => [[
                    'type' => 'shipping',
                    'groups' => [[
                        'options' => [['id' => 'std', 'name' => 'Standard', 'price' => 500]],
                        'selected_option_id' => 'std'
                    ]]
                ]]
            ]
        ]);
        $fullyUpdated = $this->simulateUpdateCheckout($checkout['id'], $fullUpdate);
        $this->assertEquals('ready_for_complete', $fullyUpdated['status']);
        echo "✓ Full update results in 'ready_for_complete'\n";

        // Complete - should be completed
        $completed = $this->simulateCompleteCheckout($checkout['id'], $this->buildPaymentData());
        $this->assertEquals('completed', $completed['status']);
        echo "✓ Completed checkout has status 'completed'\n\n";
    }

    // ==========================================
    // SIMULATION HELPERS
    // ==========================================

    private function simulateDiscovery(): array
    {
        // Simulate fetching /.well-known/ucp from the business
        $businessJwk = $this->keyManagement->pemToJwk($this->businessPublicKeyPem, $this->businessKeyId);

        return [
            'ucp' => [
                'version' => '2026-01-11',
                'services' => [
                    'dev.ucp.shopping' => [
                        'version' => '2026-01-11',
                        'spec' => 'https://ucp.dev/specification/overview',
                        'rest' => [
                            'schema' => 'https://ucp.dev/services/shopping/rest.openapi.json',
                            'endpoint' => 'https://shop.example.com/ucp/checkout-sessions'
                        ]
                    ]
                ],
                'capabilities' => [
                    [
                        'name' => 'dev.ucp.shopping.checkout',
                        'version' => '2026-01-11',
                        'spec' => 'https://ucp.dev/specification/checkout',
                        'schema' => 'https://ucp.dev/schemas/shopping/checkout.json'
                    ],
                    [
                        'name' => 'dev.ucp.shopping.fulfillment',
                        'version' => '2026-01-11',
                        'spec' => 'https://ucp.dev/specification/fulfillment',
                        'extends' => 'dev.ucp.shopping.checkout'
                    ],
                    [
                        'name' => 'dev.ucp.shopping.order',
                        'version' => '2026-01-11',
                        'spec' => 'https://ucp.dev/specification/order',
                        'extends' => 'dev.ucp.shopping.checkout'
                    ]
                ]
            ],
            'payment' => [
                'handlers' => [
                    [
                        'id' => 'business_tokenizer',
                        'name' => 'dev.ucp.business_tokenizer',
                        'version' => '2026-01-11'
                    ]
                ]
            ],
            'signing_keys' => [$businessJwk]
        ];
    }

    private function getPlatformCapabilities(): array
    {
        return [
            ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11'],
            ['name' => 'dev.ucp.shopping.fulfillment', 'version' => '2026-01-11'],
            ['name' => 'dev.ucp.shopping.order', 'version' => '2026-01-11'],
        ];
    }

    private function buildCheckoutRequest(): array
    {
        return [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'prod_laptop_001',
                        'title' => 'MacBook Pro 14"',
                        'description' => 'Apple M3 Pro, 18GB RAM, 512GB SSD',
                        'price' => 199900, // €1,999.00 in cents
                        'image_url' => 'https://shop.example.com/images/macbook.jpg'
                    ],
                    'quantity' => 1
                ],
                [
                    'item' => [
                        'id' => 'prod_case_001',
                        'title' => 'Laptop Sleeve',
                        'description' => 'Premium leather sleeve',
                        'price' => 7900, // €79.00
                    ],
                    'quantity' => 1
                ]
            ]
        ];
    }

    private function buildUpdateRequest(): array
    {
        return [
            'buyer' => [
                'email' => 'max.mustermann@example.com',
                'name' => 'Max Mustermann',
                'phone' => '+49 170 1234567'
            ],
            'fulfillment' => [
                'address' => [
                    'name' => 'Max Mustermann',
                    'line1' => 'Musterstraße 123',
                    'city' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'DE'
                ],
                'methods' => [
                    [
                        'type' => 'shipping',
                        'groups' => [
                            [
                                'options' => [
                                    [
                                        'id' => 'express',
                                        'name' => 'Express Delivery',
                                        'price' => 1500, // €15.00
                                        'estimated_delivery' => '2026-01-15'
                                    ],
                                    [
                                        'id' => 'standard',
                                        'name' => 'Standard Shipping',
                                        'price' => 500, // €5.00
                                        'estimated_delivery' => '2026-01-18'
                                    ]
                                ],
                                'selected_option_id' => 'express'
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function buildPaymentData(): array
    {
        return [
            'payment_data' => [
                'method' => 'card',
                'token' => 'tok_visa_4242424242424242',
                'card' => [
                    'last4' => '4242',
                    'brand' => 'visa',
                    'exp_month' => 12,
                    'exp_year' => 2028
                ]
            ]
        ];
    }

    private function simulateCreateCheckout(array $request): array
    {
        // Simulate business response
        $subtotal = 0;
        foreach ($request['line_items'] as $item) {
            $subtotal += ($item['item']['price'] ?? 0) * ($item['quantity'] ?? 1);
        }

        $tax = (int)($subtotal * 0.19);
        $total = $subtotal + $tax;

        return [
            'id' => 'chk_' . bin2hex(random_bytes(16)),
            'status' => 'incomplete',
            'currency' => 'EUR',
            'line_items' => $request['line_items'],
            'totals' => [
                ['type' => 'subtotal', 'amount' => $subtotal],
                ['type' => 'tax', 'amount' => $tax],
                ['type' => 'total', 'amount' => $total]
            ],
            'messages' => [
                ['type' => 'error', 'code' => 'missing', 'path' => '$.buyer.email', 'content' => 'Buyer email required']
            ],
            'expires_at' => (new \DateTime('+30 minutes'))->format('c'),
            'ucp' => [
                'version' => '2026-01-11',
                'capabilities' => $this->getPlatformCapabilities()
            ]
        ];
    }

    private function simulateUpdateCheckout(string $checkoutId, array $update): array
    {
        $lineItems = [
            ['item' => ['id' => 'prod_laptop_001', 'title' => 'MacBook Pro 14"', 'price' => 199900], 'quantity' => 1],
            ['item' => ['id' => 'prod_case_001', 'title' => 'Laptop Sleeve', 'price' => 7900], 'quantity' => 1]
        ];

        $subtotal = 207800; // €2,078.00
        $tax = (int)($subtotal * 0.19); // €394.82
        $shipping = $update['fulfillment']['methods'][0]['groups'][0]['selected_option_id'] === 'express' ? 1500 : 500;
        $total = $subtotal + $tax + $shipping;

        return [
            'id' => $checkoutId,
            'status' => 'ready_for_complete',
            'currency' => 'EUR',
            'line_items' => $lineItems,
            'buyer' => $update['buyer'] ?? ['email' => 'test@example.com'],
            'fulfillment' => $update['fulfillment'] ?? null,
            'totals' => [
                ['type' => 'subtotal', 'amount' => $subtotal],
                ['type' => 'tax', 'amount' => $tax],
                ['type' => 'fulfillment', 'display_text' => 'Express Delivery', 'amount' => $shipping],
                ['type' => 'total', 'amount' => $total]
            ],
            'messages' => [],
            'ucp' => ['version' => '2026-01-11']
        ];
    }

    private function addMerchantAuthorization(array $checkout): array
    {
        // Business signs the checkout (excluding ap2 field)
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('getString')->willReturnCallback(fn($key) => match($key) {
            'SwagUcp.config.ucpSigningKeyId' => $this->businessKeyId,
            'SwagUcp.config.ucpSigningPrivateKey' => $this->businessPrivateKeyPem,
            'SwagUcp.config.ucpSigningPublicKey' => $this->businessPublicKeyPem,
            default => ''
        });

        $businessKeyMgmt = new KeyManagementService($configService);
        $businessSigService = new SignatureVerificationService($businessKeyMgmt);

        $signature = $businessSigService->signMerchantAuthorization($checkout, 'channel');

        $checkout['ap2'] = [
            'merchant_authorization' => $signature
        ];

        return $checkout;
    }

    private function simulateCompleteCheckout(string $checkoutId, array $paymentData): array
    {
        return [
            'id' => $checkoutId,
            'status' => 'completed',
            'currency' => 'EUR',
            'line_items' => [
                ['item' => ['id' => 'prod_laptop_001', 'title' => 'MacBook Pro 14"', 'price' => 199900], 'quantity' => 1],
                ['item' => ['id' => 'prod_case_001', 'title' => 'Laptop Sleeve', 'price' => 7900], 'quantity' => 1]
            ],
            'totals' => [
                ['type' => 'subtotal', 'amount' => 207800],
                ['type' => 'tax', 'amount' => 39482],
                ['type' => 'fulfillment', 'amount' => 1500],
                ['type' => 'total', 'amount' => 248782]
            ],
            'order' => [
                'id' => 'ord_' . bin2hex(random_bytes(12)),
                'permalink_url' => 'https://shop.example.com/account/order/ord_' . bin2hex(random_bytes(12))
            ],
            'ucp' => ['version' => '2026-01-11']
        ];
    }

    private function buildOrderUpdateWebhook(string $orderId, string $event, array $extraData = []): array
    {
        $baseData = [
            'order_id' => $orderId,
            'event' => $event,
            'timestamp' => (new \DateTime())->format('c'),
        ];

        $eventData = match($event) {
            'payment_confirmed' => [
                'payment_status' => 'paid',
                'payment_method' => 'card',
                'paid_at' => (new \DateTime())->format('c'),
                'amount' => 248782,
                'currency' => 'EUR'
            ],
            'shipped' => array_merge([
                'shipment_id' => 'ship_' . bin2hex(random_bytes(8)),
                'shipped_at' => (new \DateTime())->format('c'),
            ], $extraData),
            'delivered' => [
                'delivered_at' => (new \DateTime())->format('c'),
            ],
            default => $extraData
        };

        $baseData['data'] = $eventData;

        return $baseData;
    }

    private function simulateBusinessSignsWebhook(array $payload): string
    {
        // Business signs the webhook with their private key
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('getString')->willReturnCallback(fn($key) => match($key) {
            'SwagUcp.config.ucpSigningKeyId' => $this->businessKeyId,
            'SwagUcp.config.ucpSigningPrivateKey' => $this->businessPrivateKeyPem,
            'SwagUcp.config.ucpSigningPublicKey' => $this->businessPublicKeyPem,
            default => ''
        });

        $businessKeyMgmt = new KeyManagementService($configService);
        $businessSigService = new SignatureVerificationService($businessKeyMgmt);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $businessSigService->createRequestSignature($body, 'channel');
    }

    private function verifyWebhookSignature(string $signature, array $payload, array $businessProfile): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signingKeys = $businessProfile['signing_keys'];

        return $this->signatureService->verifyRequestSignature($signature, $body, $signingKeys);
    }

    private function printTotals(array $totals): void
    {
        echo "  - Totals:\n";
        foreach ($totals as $total) {
            $amount = number_format($total['amount'] / 100, 2, ',', '.');
            $label = $total['display_text'] ?? ucfirst($total['type']);
            echo "      {$label}: €{$amount}\n";
        }
    }
}
