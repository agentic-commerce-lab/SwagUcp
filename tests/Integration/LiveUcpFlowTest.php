<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\KeyManagementService;
use SwagUcp\Service\SignatureVerificationService;

/**
 * Live UCP Flow Test
 * 
 * This test performs actual HTTP requests against the local Shopware instance
 * to validate the complete UCP flow works end-to-end.
 * 
 * Prerequisites:
 * - Shopware instance running on http://localhost
 * - SwagUcp plugin installed and activated
 * 
 * Run with: vendor/bin/phpunit tests/Integration/LiveUcpFlowTest.php --testdox
 */
class LiveUcpFlowTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1';
    
    private KeyManagementService $keyManagement;
    private SignatureVerificationService $signatureService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $configService = $this->createMock(SystemConfigService::class);
        $this->keyManagement = new KeyManagementService($configService);
        $this->signatureService = new SignatureVerificationService($this->keyManagement);
    }

    /**
     * @test
     * Discovery endpoint returns valid UCP profile
     */
    public function discoveryEndpointReturnsValidProfile(): void
    {
        $response = $this->httpGet('/.well-known/ucp');
        
        $this->assertNotNull($response, 'Discovery endpoint should be accessible');
        $this->assertArrayHasKey('ucp', $response);
        $this->assertArrayHasKey('version', $response['ucp']);
        $this->assertEquals('2026-01-11', $response['ucp']['version']);
        
        // Verify signing keys are present and valid
        $this->assertArrayHasKey('signing_keys', $response);
        $this->assertNotEmpty($response['signing_keys']);
        
        $key = $response['signing_keys'][0];
        $this->assertEquals('EC', $key['kty']);
        $this->assertEquals('P-256', $key['crv']);
        $this->assertEquals('ES256', $key['alg']);
        
        echo "\n✓ Discovery endpoint returns valid UCP profile\n";
        echo "  - Version: {$response['ucp']['version']}\n";
        echo "  - Signing Key ID: {$key['kid']}\n";
    }

    /**
     * @test
     * Complete checkout flow via HTTP
     */
    public function completeCheckoutFlowViaHttp(): void
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "LIVE UCP CHECKOUT FLOW TEST\n";
        echo str_repeat("=", 60) . "\n";

        // Step 1: Discovery
        echo "\n[1/5] DISCOVERY\n";
        $profile = $this->httpGet('/.well-known/ucp');
        $this->assertNotNull($profile);
        echo "  ✓ Fetched business profile\n";

        // Step 2: Create Checkout
        echo "\n[2/5] CREATE CHECKOUT\n";
        $createRequest = [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'test-product-001',
                        'title' => 'Test Product',
                        'price' => 2500
                    ],
                    'quantity' => 2
                ]
            ]
        ];
        
        $checkout = $this->httpPost('/ucp/checkout-sessions', $createRequest);
        $this->assertNotNull($checkout);
        $this->assertArrayHasKey('id', $checkout);
        
        $checkoutId = $checkout['id'];
        echo "  ✓ Created checkout: {$checkoutId}\n";
        echo "  - Status: {$checkout['status']}\n";
        echo "  - Currency: {$checkout['currency']}\n";

        // Step 3: Get Checkout
        echo "\n[3/5] GET CHECKOUT\n";
        $fetchedCheckout = $this->httpGet("/ucp/checkout-sessions/{$checkoutId}");
        $this->assertNotNull($fetchedCheckout);
        $this->assertEquals($checkoutId, $fetchedCheckout['id']);
        echo "  ✓ Fetched checkout successfully\n";

        // Step 4: Update Checkout
        echo "\n[4/5] UPDATE CHECKOUT\n";
        $updateRequest = [
            'buyer' => [
                'email' => 'live-test@example.com',
                'name' => 'Live Test User'
            ],
            'fulfillment' => [
                'methods' => [
                    [
                        'type' => 'shipping',
                        'groups' => [
                            [
                                'options' => [
                                    ['id' => 'standard', 'name' => 'Standard', 'price' => 500]
                                ],
                                'selected_option_id' => 'standard'
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $updatedCheckout = $this->httpPut("/ucp/checkout-sessions/{$checkoutId}", $updateRequest);
        $this->assertNotNull($updatedCheckout);
        echo "  ✓ Updated checkout\n";
        echo "  - Status: {$updatedCheckout['status']}\n";
        echo "  - Buyer: {$updatedCheckout['buyer']['email']}\n";

        // Step 5: Cancel Checkout (instead of complete, since we don't have real payment)
        echo "\n[5/5] CANCEL CHECKOUT\n";
        $cancelledCheckout = $this->httpPost("/ucp/checkout-sessions/{$checkoutId}/cancel", []);
        $this->assertNotNull($cancelledCheckout);
        $this->assertEquals('canceled', $cancelledCheckout['status']);
        echo "  ✓ Cancelled checkout\n";
        echo "  - Final Status: {$cancelledCheckout['status']}\n";

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "ALL LIVE FLOW TESTS PASSED\n";
        echo str_repeat("=", 60) . "\n";
    }

    /**
     * @test
     * Signing keys can be converted and used for verification
     */
    public function signingKeysAreUsableForVerification(): void
    {
        // Fetch profile to get signing keys
        $profile = $this->httpGet('/.well-known/ucp');
        $this->assertNotNull($profile);
        $this->assertNotEmpty($profile['signing_keys']);
        
        $jwk = $profile['signing_keys'][0];
        
        // Convert JWK to PEM
        $pem = $this->keyManagement->jwkToPem($jwk);
        $this->assertNotNull($pem, 'JWK should be convertible to PEM');
        
        // Verify the PEM is valid
        $key = openssl_pkey_get_public($pem);
        $this->assertNotFalse($key, 'Converted PEM should be a valid public key');
        
        echo "\n✓ Signing keys are valid and convertible\n";
        echo "  - Key ID: {$jwk['kid']}\n";
        echo "  - Algorithm: {$jwk['alg']}\n";
    }

    /**
     * @test
     * Checkout returns proper UCP structure
     */
    public function checkoutReturnsProperUcpStructure(): void
    {
        $checkout = $this->httpPost('/ucp/checkout-sessions', [
            'line_items' => [
                ['item' => ['id' => 'test', 'title' => 'Test', 'price' => 1000], 'quantity' => 1]
            ]
        ]);
        
        $this->assertNotNull($checkout);
        
        // Required fields per UCP spec
        $this->assertArrayHasKey('id', $checkout);
        $this->assertArrayHasKey('status', $checkout);
        $this->assertArrayHasKey('currency', $checkout);
        $this->assertArrayHasKey('line_items', $checkout);
        $this->assertArrayHasKey('totals', $checkout);
        $this->assertArrayHasKey('ucp', $checkout);
        
        // UCP metadata
        $this->assertArrayHasKey('version', $checkout['ucp']);
        
        // Totals structure
        $totalTypes = array_column($checkout['totals'], 'type');
        $this->assertContains('subtotal', $totalTypes);
        $this->assertContains('total', $totalTypes);
        
        echo "\n✓ Checkout response has proper UCP structure\n";
        
        // Cleanup
        $this->httpPost("/ucp/checkout-sessions/{$checkout['id']}/cancel", []);
    }

    /**
     * @test
     * Checkout status transitions correctly
     */
    public function checkoutStatusTransitionsCorrectly(): void
    {
        // Create -> incomplete
        $checkout = $this->httpPost('/ucp/checkout-sessions', [
            'line_items' => [
                ['item' => ['id' => 'test', 'title' => 'Test', 'price' => 1000], 'quantity' => 1]
            ]
        ]);
        $this->assertEquals('incomplete', $checkout['status']);
        echo "\n✓ New checkout has status 'incomplete'\n";
        
        // Update with buyer and fulfillment -> ready_for_complete
        $updated = $this->httpPut("/ucp/checkout-sessions/{$checkout['id']}", [
            'buyer' => ['email' => 'status-test@example.com'],
            'fulfillment' => [
                'methods' => [[
                    'type' => 'shipping',
                    'groups' => [[
                        'options' => [['id' => 'std', 'name' => 'Std', 'price' => 500]],
                        'selected_option_id' => 'std'
                    ]]
                ]]
            ]
        ]);
        $this->assertEquals('ready_for_complete', $updated['status']);
        echo "✓ Updated checkout has status 'ready_for_complete'\n";
        
        // Cancel -> canceled
        $cancelled = $this->httpPost("/ucp/checkout-sessions/{$checkout['id']}/cancel", []);
        $this->assertEquals('canceled', $cancelled['status']);
        echo "✓ Cancelled checkout has status 'canceled'\n";
    }

    // ==========================================
    // HTTP HELPERS
    // ==========================================

    private function httpGet(string $path): ?array
    {
        $url = self::BASE_URL . $path;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                ],
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
        
        return json_decode($response, true);
    }

    private function httpPost(string $path, array $data): ?array
    {
        $url = self::BASE_URL . $path;
        $body = json_encode($data);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                    'Content-Length: ' . strlen($body),
                ],
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
        
        return json_decode($response, true);
    }

    private function httpPut(string $path, array $data): ?array
    {
        $url = self::BASE_URL . $path;
        $body = json_encode($data);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                    'Content-Length: ' . strlen($body),
                ],
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
        
        return json_decode($response, true);
    }
}
