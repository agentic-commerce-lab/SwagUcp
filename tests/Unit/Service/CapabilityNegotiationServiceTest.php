<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SwagUcp\Service\CapabilityNegotiationService;

class CapabilityNegotiationServiceTest extends TestCase
{
    private CapabilityNegotiationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CapabilityNegotiationService();
    }

    public function testNegotiateBasicCapabilities(): void
    {
        $platformProfile = [
            'ucp' => [
                'version' => '2026-01-11',
                'capabilities' => [
                    ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11']
                ]
            ]
        ];

        $businessCapabilities = [
            ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11']
        ];

        $result = $this->service->negotiate($platformProfile, $businessCapabilities);

        $this->assertCount(1, $result);
        $this->assertEquals('dev.ucp.shopping.checkout', $result[0]['name']);
    }

    public function testNegotiateWithExtensions(): void
    {
        $platformProfile = [
            'ucp' => [
                'version' => '2026-01-11',
                'capabilities' => [
                    ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11'],
                    ['name' => 'dev.ucp.shopping.fulfillment', 'version' => '2026-01-11']
                ]
            ]
        ];

        $businessCapabilities = [
            ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11'],
            [
                'name' => 'dev.ucp.shopping.fulfillment',
                'version' => '2026-01-11',
                'extends' => 'dev.ucp.shopping.checkout'
            ]
        ];

        $result = $this->service->negotiate($platformProfile, $businessCapabilities);

        $this->assertCount(2, $result);
        $this->assertEquals('dev.ucp.shopping.checkout', $result[0]['name']);
        $this->assertEquals('dev.ucp.shopping.fulfillment', $result[1]['name']);
    }

    public function testNegotiatePrunesOrphanedExtensions(): void
    {
        $platformProfile = [
            'ucp' => [
                'version' => '2026-01-11',
                'capabilities' => [
                    // Platform doesn't support checkout, so fulfillment should be pruned
                ]
            ]
        ];

        $businessCapabilities = [
            ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11'],
            [
                'name' => 'dev.ucp.shopping.fulfillment',
                'version' => '2026-01-11',
                'extends' => 'dev.ucp.shopping.checkout'
            ]
        ];

        $result = $this->service->negotiate($platformProfile, $businessCapabilities);

        $this->assertCount(0, $result);
    }

    public function testVersionCompatibility(): void
    {
        $platformProfile = [
            'ucp' => [
                'version' => '2026-01-10',
                'capabilities' => [
                    ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-10']
                ]
            ]
        ];

        $businessCapabilities = [
            ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11']
        ];

        $result = $this->service->negotiate($platformProfile, $businessCapabilities);

        // Platform version is older, should be compatible
        $this->assertCount(1, $result);
    }

    public function testVersionIncompatibility(): void
    {
        $platformProfile = [
            'ucp' => [
                'version' => '2026-01-12',
                'capabilities' => [
                    ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-12']
                ]
            ]
        ];

        $businessCapabilities = [
            ['name' => 'dev.ucp.shopping.checkout', 'version' => '2026-01-11']
        ];

        $result = $this->service->negotiate($platformProfile, $businessCapabilities);

        // Platform version is newer, should not be compatible
        $this->assertCount(0, $result);
    }
}
