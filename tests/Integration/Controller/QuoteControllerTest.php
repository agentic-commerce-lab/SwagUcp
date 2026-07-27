<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Integration\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Test\TestDefaults;
use SwagUcp\Service\QuoteFeatureService;
use SwagUcp\Ucp;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration coverage for the com.shopware.quote capability.
 *
 * Tests that require SwagCommercial + a Quote Management license skip
 * gracefully when either is absent; the "without Commercial" tests only run
 * when Commercial is absent, so each environment exercises its own half.
 */
class QuoteControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    private const AGENT_HEADER = 'profile="https://agent-platform.example/.well-known/ucp"';
    private const AGENT_DOMAIN = 'agent-platform.example';

    public function testDiscoveryOmitsQuoteCapabilityWithoutCommercial(): void
    {
        $this->skipIfQuoteFeatureAvailable();

        $client = $this->createSalesChannelBrowser();
        $client->request('GET', '/.well-known/ucp');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey(Ucp::CAPABILITY_QUOTE, $data['ucp']['services'] ?? []);
    }

    public function testQuoteRoutesReturn404WithoutCommercial(): void
    {
        $this->skipIfQuoteFeatureAvailable();

        $client = $this->createSalesChannelBrowser();

        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), json_encode(['buyer' => ['email' => 'x@example.com']]));
        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $this->assertSame('quote_unavailable', $this->firstErrorCode($client->getResponse()));

        $client->request('GET', '/ucp/schemas/quote.openapi.json');
        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    public function testDiscoveryAdvertisesQuoteCapabilityWithResolvableSchema(): void
    {
        $this->skipIfQuoteFeatureUnavailable();

        $client = $this->createSalesChannelBrowser();
        $client->request('GET', '/.well-known/ucp');

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $quote = $data['ucp']['services'][Ucp::CAPABILITY_QUOTE] ?? null;

        $this->assertIsArray($quote, 'com.shopware.quote must be advertised on licensed shops');
        $this->assertSame(Ucp::QUOTE_VERSION, $quote['version']);
        $this->assertStringEndsWith('/ucp/quotes', $quote['rest']['endpoint']);
        $this->assertStringEndsWith('/ucp/schemas/quote.openapi.json', $quote['rest']['schema']);

        $client->request('GET', '/ucp/schemas/quote.openapi.json');
        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $schema = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('3.1.0', $schema['openapi']);
        $this->assertArrayHasKey('x-state-machine', $schema);
    }

    public function testFullQuoteLoop(): void
    {
        $this->skipIfQuoteFeatureUnavailable();

        $email = Uuid::randomHex() . '@example.com';
        $customerId = $this->createFlaggedCustomer($email);
        $this->authorizeAgent($customerId, self::AGENT_DOMAIN);
        $productId = $this->createProduct();

        $client = $this->createSalesChannelBrowser();

        // 1. Create RFQ
        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), (string) json_encode([
            'buyer' => ['email' => $email],
            'line_items' => [
                ['product_id' => $productId, 'quantity' => 5, 'requested_unit_price' => 7.5],
            ],
            'comment' => 'Requesting volume pricing',
        ]));

        $response = $client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $quote = json_decode((string) $response->getContent(), true);
        $this->assertSame('open', $quote['state']);
        $this->assertArrayHasKey('expiration_date', $quote);
        $this->assertSame(7.5, $quote['line_items'][0]['requested_unit_price']);
        $quoteId = $quote['id'];

        // 2. Read
        $client->request('GET', '/ucp/quotes/' . $quoteId . '?buyer_email=' . urlencode($email), [], [], $this->agentHeaders());
        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // 3. Merchant replies out-of-band
        $this->transitionQuote($quoteId, 'sent');

        // 4. Counter-offer
        $client->request('POST', '/ucp/quotes/' . $quoteId . '/counter', [], [], $this->agentHeaders(), (string) json_encode([
            'buyer' => ['email' => $email],
            'line_items' => [['product_id' => $productId, 'requested_unit_price' => 8.0]],
            'comment' => 'Can you do 8?',
        ]));
        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $countered = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('change_requested', $countered['state']);

        // 5. Merchant replies again, buyer accepts -> order reference
        $this->transitionQuote($quoteId, 'admin_resend');

        $client->request('POST', '/ucp/quotes/' . $quoteId . '/accept', [], [], $this->agentHeaders(), (string) json_encode([
            'buyer' => ['email' => $email],
        ]));
        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $accepted = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $accepted['state']);
        $this->assertNotEmpty($accepted['order']['id']);
    }

    public function testNegativeCasesReturnSpecificErrorCodes(): void
    {
        $this->skipIfQuoteFeatureUnavailable();

        $client = $this->createSalesChannelBrowser();
        $payload = fn (string $email) => (string) json_encode([
            'buyer' => ['email' => $email],
            'line_items' => [['product_id' => Uuid::randomHex(), 'quantity' => 1]],
        ]);

        // Unknown buyer -> 404 buyer_not_found
        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), $payload('unknown@example.com'));
        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $this->assertSame('buyer_not_found', $this->firstErrorCode($client->getResponse()));

        // Known buyer, agent not authorized -> 403 agent_not_authorized
        $email = Uuid::randomHex() . '@example.com';
        $customerId = $this->createFlaggedCustomer($email);
        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), $payload($email));
        $this->assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $this->assertSame('agent_not_authorized', $this->firstErrorCode($client->getResponse()));

        // Authorized agent but customer not flagged -> 403 quote_not_enabled_for_buyer
        $unflaggedEmail = Uuid::randomHex() . '@example.com';
        $unflaggedId = $this->createCustomerRecord($unflaggedEmail);
        $this->authorizeAgent($unflaggedId, self::AGENT_DOMAIN);
        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), $payload($unflaggedEmail));
        $this->assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $this->assertSame('quote_not_enabled_for_buyer', $this->firstErrorCode($client->getResponse()));

        // Foreign quote id -> 404 (not 403 - existence is never confirmed)
        $this->authorizeAgent($customerId, self::AGENT_DOMAIN);
        $client->request(
            'GET',
            '/ucp/quotes/' . Uuid::randomHex() . '?buyer_email=' . urlencode($email),
            [],
            [],
            $this->agentHeaders()
        );
        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    public function testRevokingAuthorizationImmediatelyBlocksOperations(): void
    {
        $this->skipIfQuoteFeatureUnavailable();

        $email = Uuid::randomHex() . '@example.com';
        $customerId = $this->createFlaggedCustomer($email);
        $authorizationId = $this->authorizeAgent($customerId, self::AGENT_DOMAIN);
        $productId = $this->createProduct();

        $client = $this->createSalesChannelBrowser();
        $body = (string) json_encode([
            'buyer' => ['email' => $email],
            'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);

        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), $body);
        $this->assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        $this->getContainer()->get('swag_ucp_agent_authorization.repository')->update([
            ['id' => $authorizationId, 'revokedAt' => new \DateTimeImmutable()],
        ], Context::createDefaultContext());

        $client->request('POST', '/ucp/quotes', [], [], $this->agentHeaders(), $body);
        $this->assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $this->assertSame('agent_not_authorized', $this->firstErrorCode($client->getResponse()));
    }

    private function skipIfQuoteFeatureAvailable(): void
    {
        if ($this->getContainer()->get(QuoteFeatureService::class)->isAvailable()) {
            $this->markTestSkipped('SwagCommercial with Quote Management is installed; this test covers the plain setup');
        }
    }

    private function skipIfQuoteFeatureUnavailable(): void
    {
        if (!$this->getContainer()->get(QuoteFeatureService::class)->isAvailable()) {
            $this->markTestSkipped('Requires SwagCommercial with a licensed Quote Management feature');
        }
    }

    /**
     * @return array<string, string>
     */
    private function agentHeaders(): array
    {
        return [
            'HTTP_UCP-Agent' => self::AGENT_HEADER,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function firstErrorCode(Response $response): ?string
    {
        $data = json_decode((string) $response->getContent(), true);

        return $data['messages'][0]['code'] ?? null;
    }

    private function createFlaggedCustomer(string $email): string
    {
        $customerId = $this->createCustomerRecord($email);

        // features is a map keyed by feature name - {"QUOTE_MANAGEMENT": true}
        $this->getContainer()->get(Connection::class)->insert('customer_specific_features', [
            'id' => Uuid::randomBytes(),
            'customer_id' => Uuid::fromHexToBytes($customerId),
            'features' => json_encode(['QUOTE_MANAGEMENT' => true], \JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $customerId;
    }

    private function createCustomerRecord(string $email): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();

        $this->getContainer()->get('customer.repository')->create([[
            'id' => $customerId,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'defaultPaymentMethodId' => $this->getValidPaymentMethodId(),
            'groupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'customerNumber' => Uuid::randomHex(),
            'firstName' => 'Quote',
            'lastName' => 'Buyer',
            'email' => $email,
            'password' => TestDefaults::HASHED_PASSWORD,
            'active' => true,
            'defaultShippingAddress' => [
                'id' => $addressId,
                'firstName' => 'Quote',
                'lastName' => 'Buyer',
                'street' => 'Musterstraße 1',
                'city' => 'Schöppingen',
                'zipcode' => '12345',
                'countryId' => $this->getValidCountryId(),
            ],
            'defaultBillingAddressId' => $addressId,
        ]], Context::createDefaultContext());

        return $customerId;
    }

    private function authorizeAgent(string $customerId, string $agentDomain): string
    {
        $id = Uuid::randomHex();
        $this->getContainer()->get('swag_ucp_agent_authorization.repository')->create([[
            'id' => $id,
            'customerId' => $customerId,
            'agentDomain' => $agentDomain,
        ]], Context::createDefaultContext());

        return $id;
    }

    private function createProduct(): string
    {
        $productId = Uuid::randomHex();

        $this->getContainer()->get('product.repository')->create([[
            'id' => $productId,
            'productNumber' => Uuid::randomHex(),
            'stock' => 100,
            'name' => 'Quotable Product',
            'tax' => ['name' => 'test', 'taxRate' => 19],
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => 11.9,
                'net' => 10.0,
                'linked' => false,
            ]],
            'visibilities' => [[
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
                'visibility' => 30,
            ]],
        ]], Context::createDefaultContext());

        return $productId;
    }

    private function transitionQuote(string $quoteId, string $action): void
    {
        $this->getContainer()->get(StateMachineRegistry::class)->transition(
            new Transition('quote', $quoteId, $action, 'stateId'),
            Context::createDefaultContext()
        );
    }
}
