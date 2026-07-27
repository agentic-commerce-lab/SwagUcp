<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SwagUcp\Service\QuoteFeatureService;

class QuoteFeatureServiceTest extends TestCase
{
    public function testUnavailableWithoutCommercialClasses(): void
    {
        $service = new QuoteFeatureService();

        // SwagCommercial is not installed in the unit test environment
        $this->assertFalse($service->isAvailable());
    }

    public function testAvailabilityCheckOverride(): void
    {
        $available = new QuoteFeatureService(fn () => true);
        $unavailable = new QuoteFeatureService(fn () => false);

        $this->assertTrue($available->isAvailable());
        $this->assertFalse($unavailable->isAvailable());
    }
}
