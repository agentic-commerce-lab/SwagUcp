<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SwagUcp\Service\UcpCompatibilityService;
use SwagUcp\Ucp;

class UcpCompatibilityServiceTest extends TestCase
{
    private UcpCompatibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UcpCompatibilityService();
    }

    public function testBuildProfileForVersion2026_01_11UsesArrayCapabilitiesAndPaymentHandlers(): void
    {
        $profile = [
            'ucp' => [
                'version' => Ucp::VERSION_2026_01_11,
                'services' => [],
            ],
            'signing_keys' => [],
        ];

        $capabilities = [
            [
                'name' => Ucp::CAPABILITY_CHECKOUT,
                'version' => Ucp::VERSION_2026_01_11,
                'spec' => Ucp::SPEC_CHECKOUT,
                'schema' => Ucp::SCHEMA_CHECKOUT,
            ],
        ];

        $handlers = [
            [
                'id' => 'handler_1',
                'name' => Ucp::CAPABILITY_BUSINESS_TOKENIZER,
                'version' => Ucp::VERSION_2026_01_11,
            ],
        ];

        $result = $this->service->buildProfile($profile, Ucp::VERSION_2026_01_11, $capabilities, $handlers);

        $this->assertArrayHasKey('ucp', $result);
        $this->assertArrayHasKey('capabilities', $result['ucp']);
        $this->assertArrayHasKey('payment', $result['ucp']);
        $this->assertArrayHasKey('handlers', $result['ucp']['payment']);

        $this->assertIsArray($result['ucp']['capabilities']);
        $this->assertCount(1, $result['ucp']['capabilities']);
        $this->assertSame(Ucp::CAPABILITY_CHECKOUT, $result['ucp']['capabilities'][0]['name']);

        $this->assertSame($handlers, $result['ucp']['payment']['handlers']);
        $this->assertArrayHasKey('signing_keys', $result);
    }

    public function testBuildProfileForCurrentVersionUsesNameMappedCapabilitiesAndPaymentHandler(): void
    {
        $profile = [
            'ucp' => [
                'version' => Ucp::VERSION,
                'services' => [],
            ],
            'signing_keys' => [],
        ];

        $capabilities = [
            [
                'name' => Ucp::CAPABILITY_CHECKOUT,
                'version' => Ucp::VERSION,
                'spec' => Ucp::SPEC_CHECKOUT,
                'schema' => Ucp::SCHEMA_CHECKOUT,
            ],
        ];

        $handlers = [
            [
                'id' => 'handler_1',
                'name' => Ucp::CAPABILITY_BUSINESS_TOKENIZER,
                'version' => Ucp::VERSION,
                'config_schema' => Ucp::SCHEMA_DELEGATE_PAYMENT,
            ],
        ];

        $result = $this->service->buildProfile($profile, Ucp::VERSION, $capabilities, $handlers);

        $this->assertArrayHasKey('ucp', $result);
        $this->assertArrayHasKey('capabilities', $result['ucp']);
        $this->assertArrayHasKey('payment_handlers', $result['ucp']);
        $this->assertArrayNotHasKey('payment', $result['ucp']);

        $this->assertIsArray($result['ucp']['capabilities']);
        $this->assertArrayHasKey(Ucp::CAPABILITY_CHECKOUT, $result['ucp']['capabilities']);
        $cap = $result['ucp']['capabilities'][Ucp::CAPABILITY_CHECKOUT];
        $this->assertArrayNotHasKey('name', $cap);
        $this->assertSame(Ucp::VERSION, $cap['version']);
        $this->assertSame(Ucp::SPEC_CHECKOUT, $cap['spec']);

        $this->assertArrayHasKey(Ucp::CAPABILITY_BUSINESS_TOKENIZER, $result['ucp']['payment_handlers']);
        $handler = $result['ucp']['payment_handlers'][Ucp::CAPABILITY_BUSINESS_TOKENIZER];
        $this->assertArrayNotHasKey('name', $handler);
        $this->assertSame('handler_1', $handler['id']);
        $this->assertArrayHasKey('schema', $handler, 'Current version should expose config_schema as schema');
        $this->assertSame(Ucp::SCHEMA_DELEGATE_PAYMENT, $handler['schema']);
        $this->assertArrayNotHasKey('config_schema', $handler);
    }

    public function testBuildProfilePreservesExistingUcpKeys(): void
    {
        $profile = [
            'ucp' => [
                'version' => '2026-01-11',
                'services' => ['dev.ucp.shopping' => ['spec' => 'overview']],
            ],
            'signing_keys' => [['kid' => 'k1']],
        ];

        $result = $this->service->buildProfile($profile, Ucp::VERSION_2026_01_11, [], []);

        $this->assertSame('2026-01-11', $result['ucp']['version']);
        $this->assertSame(['dev.ucp.shopping' => ['spec' => 'overview']], $result['ucp']['services']);
        $this->assertSame([['kid' => 'k1']], $result['signing_keys']);
        $this->assertArrayHasKey('capabilities', $result['ucp']);
        $this->assertArrayHasKey('payment', $result['ucp']);
    }
}
