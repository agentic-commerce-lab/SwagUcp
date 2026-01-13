<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagUcp\Service\DiscoveryService;
use SwagUcp\Service\KeyManagementService;

class DiscoveryServiceTest extends TestCase
{
    private DiscoveryService $service;
    private SystemConfigService $systemConfigService;
    private KeyManagementService $keyManagementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->keyManagementService = $this->createMock(KeyManagementService::class);

        $this->service = new DiscoveryService(
            $this->systemConfigService,
            $this->keyManagementService
        );
    }

    public function testGetUcpVersion(): void
    {
        $this->systemConfigService
            ->expects($this->once())
            ->method('getString')
            ->with('SwagUcp.config.ucpVersion', 'test-channel')
            ->willReturn('2026-01-11');

        $version = $this->service->getUcpVersion('test-channel');
        $this->assertEquals('2026-01-11', $version);
    }

    public function testGetUcpVersionDefault(): void
    {
        $this->systemConfigService
            ->expects($this->once())
            ->method('getString')
            ->with('SwagUcp.config.ucpVersion', 'test-channel')
            ->willReturn('');

        $version = $this->service->getUcpVersion('test-channel');
        $this->assertEquals('2026-01-11', $version);
    }

    public function testGetCapabilities(): void
    {
        $this->systemConfigService
            ->method('getString')
            ->willReturn('2026-01-11');

        $capabilities = $this->service->getCapabilities('test-channel');

        $this->assertIsArray($capabilities);
        $this->assertGreaterThanOrEqual(1, count($capabilities));

        $checkoutCap = null;
        foreach ($capabilities as $cap) {
            if ($cap['name'] === 'dev.ucp.shopping.checkout') {
                $checkoutCap = $cap;
                break;
            }
        }

        $this->assertNotNull($checkoutCap);
        $this->assertEquals('2026-01-11', $checkoutCap['version']);
        $this->assertArrayHasKey('spec', $checkoutCap);
        $this->assertArrayHasKey('schema', $checkoutCap);
    }

    public function testGetSigningKeys(): void
    {
        $expectedKeys = [
            [
                'kid' => 'test_key',
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => 'test_x',
                'y' => 'test_y',
                'use' => 'sig',
                'alg' => 'ES256'
            ]
        ];

        $this->keyManagementService
            ->expects($this->once())
            ->method('getPublicKeys')
            ->with('test-channel')
            ->willReturn($expectedKeys);

        $keys = $this->service->getSigningKeys('test-channel');
        $this->assertEquals($expectedKeys, $keys);
    }
}
