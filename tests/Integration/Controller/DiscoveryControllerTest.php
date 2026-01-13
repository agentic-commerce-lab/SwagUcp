<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
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
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('ucp', $data);
        $this->assertArrayHasKey('payment', $data);
        $this->assertArrayHasKey('signing_keys', $data);
        
        $this->assertArrayHasKey('version', $data['ucp']);
        $this->assertArrayHasKey('services', $data['ucp']);
        $this->assertArrayHasKey('capabilities', $data['ucp']);
        
        $this->assertArrayHasKey('dev.ucp.shopping', $data['ucp']['services']);
        $this->assertArrayHasKey('rest', $data['ucp']['services']['dev.ucp.shopping']);
        $this->assertArrayHasKey('mcp', $data['ucp']['services']['dev.ucp.shopping']);
    }

    public function testProfileContainsCheckoutCapability(): void
    {
        $client = $this->createSalesChannelBrowser();
        
        $client->request('GET', '/.well-known/ucp');
        
        $data = json_decode($client->getResponse()->getContent(), true);
        
        $hasCheckout = false;
        foreach ($data['ucp']['capabilities'] as $capability) {
            if ($capability['name'] === 'dev.ucp.shopping.checkout') {
                $hasCheckout = true;
                $this->assertEquals('2026-01-11', $capability['version']);
                $this->assertArrayHasKey('spec', $capability);
                $this->assertArrayHasKey('schema', $capability);
                break;
            }
        }
        
        $this->assertTrue($hasCheckout, 'Checkout capability should be present');
    }
}
