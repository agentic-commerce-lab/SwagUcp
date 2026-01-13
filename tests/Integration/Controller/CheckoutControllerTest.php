<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Symfony\Component\HttpFoundation\Response;

class CheckoutControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testCreateCheckout(): void
    {
        $client = $this->createSalesChannelBrowser();
        
        $checkoutData = [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'test-product-1',
                        'title' => 'Test Product',
                        'price' => 1000
                    ],
                    'quantity' => 1
                ]
            ],
            'currency' => 'EUR'
        ];
        
        $client->request(
            'POST',
            '/api/ucp/checkout-sessions',
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($checkoutData)
        );
        
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('ucp', $data);
        $this->assertArrayHasKey('line_items', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('payment', $data);
        
        $this->assertEquals('incomplete', $data['status']);
        $this->assertArrayHasKey('handlers', $data['payment']);
    }

    public function testCreateCheckoutWithBuyer(): void
    {
        $client = $this->createSalesChannelBrowser();
        
        $checkoutData = [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'test-product-1',
                        'title' => 'Test Product',
                        'price' => 1000
                    ],
                    'quantity' => 1
                ]
            ],
            'buyer' => [
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User'
            ],
            'currency' => 'EUR'
        ];
        
        $client->request(
            'POST',
            '/api/ucp/checkout-sessions',
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($checkoutData)
        );
        
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('buyer', $data);
        $this->assertEquals('test@example.com', $data['buyer']['email']);
    }

    public function testGetCheckout(): void
    {
        $client = $this->createSalesChannelBrowser();
        
        // First create a checkout
        $checkoutData = [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'test-product-1',
                        'title' => 'Test Product',
                        'price' => 1000
                    ],
                    'quantity' => 1
                ]
            ]
        ];
        
        $client->request(
            'POST',
            '/api/ucp/checkout-sessions',
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($checkoutData)
        );
        
        $createResponse = json_decode($client->getResponse()->getContent(), true);
        $checkoutId = $createResponse['id'];
        
        // Then get it
        $client->request(
            'GET',
            "/api/ucp/checkout-sessions/{$checkoutId}",
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"'
            ]
        );
        
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals($checkoutId, $data['id']);
    }

    public function testUpdateCheckout(): void
    {
        $client = $this->createSalesChannelBrowser();
        
        // Create checkout
        $checkoutData = [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'test-product-1',
                        'title' => 'Test Product',
                        'price' => 1000
                    ],
                    'quantity' => 1
                ]
            ]
        ];
        
        $client->request(
            'POST',
            '/api/ucp/checkout-sessions',
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($checkoutData)
        );
        
        $createResponse = json_decode($client->getResponse()->getContent(), true);
        $checkoutId = $createResponse['id'];
        
        // Update checkout
        $updateData = [
            'id' => $checkoutId,
            'buyer' => [
                'email' => 'updated@example.com'
            ],
            'line_items' => $checkoutData['line_items']
        ];
        
        $client->request(
            'PUT',
            "/api/ucp/checkout-sessions/{$checkoutId}",
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($updateData)
        );
        
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('updated@example.com', $data['buyer']['email']);
    }

    public function testCancelCheckout(): void
    {
        $client = $this->createSalesChannelBrowser();
        
        // Create checkout
        $checkoutData = [
            'line_items' => [
                [
                    'item' => [
                        'id' => 'test-product-1',
                        'title' => 'Test Product',
                        'price' => 1000
                    ],
                    'quantity' => 1
                ]
            ]
        ];
        
        $client->request(
            'POST',
            '/api/ucp/checkout-sessions',
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($checkoutData)
        );
        
        $createResponse = json_decode($client->getResponse()->getContent(), true);
        $checkoutId = $createResponse['id'];
        
        // Cancel checkout
        $client->request(
            'POST',
            "/api/ucp/checkout-sessions/{$checkoutId}/cancel",
            [],
            [],
            [
                'HTTP_UCP-Agent' => 'profile="https://test-platform.example/profile"'
            ]
        );
        
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('canceled', $data['status']);
    }
}
