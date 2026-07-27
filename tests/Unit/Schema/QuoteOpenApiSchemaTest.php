<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

class QuoteOpenApiSchemaTest extends TestCase
{
    private array $schema;

    protected function setUp(): void
    {
        $file = __DIR__ . '/../../../src/Resources/schemas/quote.openapi.json';
        $this->assertFileExists($file);

        $this->schema = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testIsOpenApi31WithCapabilityIdentity(): void
    {
        $this->assertSame('3.1.0', $this->schema['openapi']);
        $this->assertSame('com.shopware.quote', $this->schema['info']['title']);
        $this->assertSame(\SwagUcp\Ucp::QUOTE_VERSION, $this->schema['info']['version']);
    }

    public function testAllFiveOperationsAreDocumented(): void
    {
        $paths = $this->schema['paths'];

        $this->assertArrayHasKey('post', $paths['/ucp/quotes']);
        $this->assertArrayHasKey('get', $paths['/ucp/quotes/{id}']);
        $this->assertArrayHasKey('post', $paths['/ucp/quotes/{id}/counter']);
        $this->assertArrayHasKey('post', $paths['/ucp/quotes/{id}/accept']);
        $this->assertArrayHasKey('post', $paths['/ucp/quotes/{id}/decline']);
    }

    public function testStateMachineCoversBuyerVisibleStates(): void
    {
        $stateMachine = $this->schema['x-state-machine'];

        foreach (['open', 'in_review', 'replied', 'change_requested', 'accepted', 'declined', 'expired'] as $state) {
            $this->assertArrayHasKey($state, $stateMachine['states'], "missing state {$state}");
        }

        $this->assertSame(['accept', 'counter', 'decline'], $stateMachine['states']['replied']['buyer_actions']);

        $buyerTransitions = array_filter($stateMachine['transitions'], fn (array $t) => $t['actor'] === 'buyer');
        $this->assertNotEmpty($buyerTransitions);
    }

    public function testErrorCodesAreEnumerated(): void
    {
        $codes = $this->schema['components']['schemas']['Error']['properties']['messages']['items']['properties']['code']['examples'];

        foreach (['unauthorized', 'buyer_not_found', 'agent_not_authorized', 'quote_not_enabled_for_buyer', 'CHECKOUT__QUOTE_CANNOT_PLACE_ORDER'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function testBehavioralContractsAreDocumented(): void
    {
        $this->assertIsInt($this->schema['x-polling-interval-seconds']);
        $this->assertStringContainsString('expiration_date', $this->schema['info']['description']);
        $this->assertStringContainsString('per unit', $this->schema['info']['description']);

        $quoteSchema = $this->schema['components']['schemas']['Quote'];
        $this->assertContains('expiration_date', $quoteSchema['required']);
    }
}
