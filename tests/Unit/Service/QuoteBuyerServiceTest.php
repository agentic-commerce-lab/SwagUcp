<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Service\QuoteAccessException;
use SwagUcp\Service\QuoteBuyerService;

class QuoteBuyerServiceTest extends TestCase
{
    private EntityRepository $customerRepository;
    private EntityRepository $authorizationRepository;
    private AbstractSalesChannelContextFactory $contextFactory;
    private SalesChannelContext $anonymousContext;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(EntityRepository::class);
        $this->authorizationRepository = $this->createMock(EntityRepository::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);

        $this->anonymousContext = $this->createMock(SalesChannelContext::class);
        $this->anonymousContext->method('getSalesChannelId')->willReturn('sales-channel-id');
        $this->anonymousContext->method('getContext')->willReturn(Context::createDefaultContext());
    }

    public function testMissingBuyerClaimThrowsBuyerNotFound(): void
    {
        $service = $this->createService(featureAllowed: true);

        $this->expectExceptionObject(QuoteAccessException::buyerNotFound());
        $service->resolveContext('agent.example.com', null, null, $this->anonymousContext);
    }

    public function testUnknownBuyerThrowsBuyerNotFound(): void
    {
        $this->mockCustomerId(null);
        $service = $this->createService(featureAllowed: true);

        try {
            $service->resolveContext('agent.example.com', 'nobody@example.com', null, $this->anonymousContext);
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('buyer_not_found', $e->getErrorCode());
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testMissingAuthorizationThrowsAgentNotAuthorized(): void
    {
        $this->mockCustomerId('customer-id');
        $this->mockAuthorizationCount(0);
        $service = $this->createService(featureAllowed: true);

        try {
            $service->resolveContext('agent.example.com', 'buyer@example.com', null, $this->anonymousContext);
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('agent_not_authorized', $e->getErrorCode());
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testMissingAgentDomainThrowsAgentNotAuthorized(): void
    {
        $this->mockCustomerId('customer-id');
        $service = $this->createService(featureAllowed: true);

        try {
            $service->resolveContext(null, 'buyer@example.com', null, $this->anonymousContext);
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('agent_not_authorized', $e->getErrorCode());
        }
    }

    public function testUnflaggedCustomerThrowsQuoteNotEnabled(): void
    {
        $this->mockCustomerId('customer-id');
        $this->mockAuthorizationCount(1);
        $service = $this->createService(featureAllowed: false);

        try {
            $service->resolveContext('agent.example.com', 'buyer@example.com', null, $this->anonymousContext);
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('quote_not_enabled_for_buyer', $e->getErrorCode());
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testNullFeatureServiceThrowsQuoteNotEnabled(): void
    {
        $this->mockCustomerId('customer-id');
        $this->mockAuthorizationCount(1);
        $service = new QuoteBuyerService(
            $this->customerRepository,
            $this->authorizationRepository,
            null,
            $this->contextFactory,
        );

        $this->expectException(QuoteAccessException::class);
        $service->resolveContext('agent.example.com', 'buyer@example.com', null, $this->anonymousContext);
    }

    public function testAllChecksPassBuildsCustomerContext(): void
    {
        $this->mockCustomerId('customer-id');
        $this->mockAuthorizationCount(1);

        $customerContext = $this->createMock(SalesChannelContext::class);
        $this->contextFactory
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->isType('string'),
                'sales-channel-id',
                [SalesChannelContextService::CUSTOMER_ID => 'customer-id']
            )
            ->willReturn($customerContext);

        $service = $this->createService(featureAllowed: true);
        $result = $service->resolveContext('agent.example.com', 'buyer@example.com', null, $this->anonymousContext);

        $this->assertSame($customerContext, $result);
    }

    public function testTokenContextForUnknownCustomerThrowsBuyerNotFound(): void
    {
        $this->mockCustomerId(null);
        $service = $this->createService(featureAllowed: true);

        try {
            $service->resolveTokenContext('unknown-customer-id', $this->anonymousContext);
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('buyer_not_found', $e->getErrorCode());
        }
    }

    public function testTokenContextForUnflaggedCustomerThrowsQuoteNotEnabled(): void
    {
        $this->mockCustomerId('customer-id');
        $service = $this->createService(featureAllowed: false);

        try {
            $service->resolveTokenContext('customer-id', $this->anonymousContext);
            $this->fail('Expected QuoteAccessException');
        } catch (QuoteAccessException $e) {
            $this->assertSame('quote_not_enabled_for_buyer', $e->getErrorCode());
        }
    }

    public function testTokenContextSkipsAuthorizationRecordCheck(): void
    {
        $this->mockCustomerId('customer-id');
        // No authorization record exists - the OAuth grant itself is the authorization
        $this->authorizationRepository->expects($this->never())->method('searchIds');

        $customerContext = $this->createMock(SalesChannelContext::class);
        $this->contextFactory
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->isType('string'),
                'sales-channel-id',
                [SalesChannelContextService::CUSTOMER_ID => 'customer-id']
            )
            ->willReturn($customerContext);

        $service = $this->createService(featureAllowed: true);
        $result = $service->resolveTokenContext('customer-id', $this->anonymousContext);

        $this->assertSame($customerContext, $result);
    }

    private function createService(bool $featureAllowed): QuoteBuyerService
    {
        $featureService = new class($featureAllowed) {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function isAllowed(?string $customerId, string $feature): bool
            {
                return $this->allowed;
            }
        };

        return new QuoteBuyerService(
            $this->customerRepository,
            $this->authorizationRepository,
            $featureService,
            $this->contextFactory,
        );
    }

    private function mockCustomerId(?string $id): void
    {
        $result = $this->createMock(IdSearchResult::class);
        $result->method('firstId')->willReturn($id);
        $this->customerRepository->method('searchIds')->willReturn($result);
    }

    private function mockAuthorizationCount(int $total): void
    {
        $result = $this->createMock(IdSearchResult::class);
        $result->method('getTotal')->willReturn($total);
        $this->authorizationRepository->method('searchIds')->willReturn($result);
    }
}
