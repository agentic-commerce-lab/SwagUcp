<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagUcp\Service\QuoteService;

class QuoteServiceTest extends TestCase
{
    public function testThrowsWhenCommercialRoutesMissing(): void
    {
        $service = new QuoteService(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->createMock(CartService::class),
            $this->createMock(LineItemFactoryRegistry::class),
            $this->createMock(EntityRepository::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unavailable/');
        $service->read('some-id', $this->createMock(SalesChannelContext::class));
    }

    public function testCreateRejectsEmptyLineItems(): void
    {
        $service = $this->serviceWithStubRoutes();

        $this->expectException(\InvalidArgumentException::class);
        $service->create(['line_items' => []], $this->createMock(SalesChannelContext::class));
    }

    public function testCreateFillsCartAppliesRequestedPriceAndSends(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn('cart-token');

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn(new Cart('cart-token'));
        $cartService->expects($this->once())->method('add');

        $factory = $this->createMock(LineItemFactoryRegistry::class);
        $factory->method('create')->willReturn(
            new LineItem('li', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-1', 5)
        );

        $calls = new \ArrayObject();
        $service = $this->serviceWithStubRoutes($calls, $cartService, $factory);

        $quote = $service->create(
            [
                'line_items' => [
                    ['product_id' => 'product-1', 'quantity' => 5, 'requested_unit_price' => 8.5],
                ],
                'comment' => 'need a deal',
            ],
            $context
        );

        $this->assertSame('quote-1', $quote->getId());
        $this->assertSame(
            ['request', 'edit:qli-1:8.5', 'sendRequest:need a deal', 'load'],
            $calls->getArrayCopy()
        );
    }

    private function serviceWithStubRoutes(
        ?\ArrayObject $calls = null,
        ?CartService $cartService = null,
        ?LineItemFactoryRegistry $factory = null
    ): QuoteService {
        $calls ??= new \ArrayObject();

        $quoteStub = new class {
            public function getId(): string
            {
                return 'quote-1';
            }

            public function getLineItems(): array
            {
                return [
                    new class {
                        public function getId(): string
                        {
                            return 'qli-1';
                        }

                        public function getProductId(): ?string
                        {
                            return 'product-1';
                        }
                    },
                ];
            }
        };

        $response = new class($quoteStub) {
            public function __construct(private readonly object $quote)
            {
            }

            public function getQuote(): object
            {
                return $this->quote;
            }
        };

        $requestRoute = new class($calls, $response) {
            public function __construct(private readonly \ArrayObject $calls, private readonly object $response)
            {
            }

            public function request(SalesChannelContext $context): object
            {
                $this->calls[] = 'request';

                return $this->response;
            }
        };

        $sendRoute = new class($calls) {
            public function __construct(private readonly \ArrayObject $calls)
            {
            }

            public function sendRequest(SalesChannelContext $context, string $id, RequestDataBag $bag): void
            {
                $this->calls[] = 'sendRequest:' . $bag->getString('comment');
            }
        };

        $lineItemRoute = new class($calls) {
            public function __construct(private readonly \ArrayObject $calls)
            {
            }

            public function edit(string $quoteId, string $lineItemId, SalesChannelContext $context, RequestDataBag $bag): void
            {
                $this->calls[] = 'edit:' . $lineItemId . ':' . $bag->get('requestedPrice');
            }
        };

        $loadRoute = new class($calls, $response) {
            public function __construct(private readonly \ArrayObject $calls, private readonly object $response)
            {
            }

            public function load(string $id, SalesChannelContext $context, object $criteria): object
            {
                $this->calls[] = 'load';

                return $this->response;
            }
        };

        $noop = new class {
            public function requestChange(SalesChannelContext $context, string $id, RequestDataBag $bag): void
            {
            }

            public function decline(SalesChannelContext $context, string $id, RequestDataBag $bag): void
            {
            }

            public function order(SalesChannelContext $context, RequestDataBag $bag, string $id): object
            {
                return new class {
                    public function getOrder(): object
                    {
                        return new \stdClass();
                    }
                };
            }
        };

        return new QuoteService(
            $requestRoute,
            $sendRoute,
            $lineItemRoute,
            $loadRoute,
            $noop,
            $noop,
            $noop,
            $cartService ?? $this->createMock(CartService::class),
            $factory ?? $this->createMock(LineItemFactoryRegistry::class),
            $this->createMock(EntityRepository::class),
        );
    }
}
