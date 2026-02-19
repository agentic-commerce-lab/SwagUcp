<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use SwagUcp\Ucp;
use Symfony\Component\HttpFoundation\Response;

class DiscoveryControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testGetProfile(): void
    {
        $client = $this->createSalesChannelBrowser();
        $client->request('GET', '/.well-known/ucp');

        $response = $client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type') ?? '');

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('ucp', $data);
        $this->assertArrayHasKey('signing_keys', $data);

        $ucp = $data['ucp'];
        $this->assertArrayHasKey('version', $ucp);
        $this->assertArrayHasKey('services', $ucp);
        $this->assertArrayHasKey('capabilities', $ucp);
        $this->assertTrue(
            isset($data['ucp']['payment']) || isset($data['ucp']['payment_handlers']),
            'Profile must contain ucp.payment or ucp.payment_handlers'
        );

        $this->assertArrayHasKey(Ucp::CAPABILITY_SHOPPING, $ucp['services']);
        $shopping = $ucp['services'][Ucp::CAPABILITY_SHOPPING];
        $this->assertArrayHasKey('rest', $shopping);
        $this->assertArrayHasKey('mcp', $shopping);
    }

    public function testProfileContainsCheckoutCapability(): void
    {
        $client = $this->createSalesChannelBrowser();
        $client->request('GET', '/.well-known/ucp');

        $data = json_decode($client->getResponse()->getContent(), true);
        $checkoutCap = $this->findCapability($data['ucp']['capabilities'], Ucp::CAPABILITY_CHECKOUT);

        $this->assertNotNull($checkoutCap, 'Checkout capability should be present');
        $this->assertArrayHasKey('version', $checkoutCap);
        $this->assertArrayHasKey('spec', $checkoutCap);
        $this->assertArrayHasKey('schema', $checkoutCap);
    }

    public function testPaymentSectionHasExpectedStructure(): void
    {
        $client = $this->createSalesChannelBrowser();
        $client->request('GET', '/.well-known/ucp');

        $data = json_decode($client->getResponse()->getContent(), true);

        $ucp = $data['ucp'];
        if (isset($ucp['payment'])) {
            $this->assertArrayHasKey('handlers', $ucp['payment']);
            $this->assertIsArray($ucp['payment']['handlers']);
        } else {
            $this->assertArrayHasKey('payment_handlers', $ucp);
            $this->assertIsArray($ucp['payment_handlers']);
            $this->assertNotEmpty($ucp['payment_handlers'], 'At least one payment handler should be exposed');
        }
    }

    /**
     * @param array<int|string, mixed> $capabilities list of capability arrays or name => capability map
     *
     * @return array<string, mixed>|null
     */
    private function findCapability(array $capabilities, string $name): ?array
    {
        if (array_is_list($capabilities)) {
            foreach ($capabilities as $cap) {
                if (($cap['name'] ?? '') === $name) {
                    return $cap;
                }
            }

            return null;
        }

        $cap = $capabilities[$name] ?? null;

        return is_array($cap) ? $cap : null;
    }
}
