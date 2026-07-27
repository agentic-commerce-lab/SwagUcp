<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Mapper;

use PHPUnit\Framework\TestCase;
use SwagUcp\Mapper\QuoteMapper;

class QuoteMapperTest extends TestCase
{
    public function testMapFullQuote(): void
    {
        $quote = $this->quoteStub();

        $mapped = (new QuoteMapper())->map($quote);

        $this->assertSame('quote-id', $mapped['id']);
        $this->assertSame('QT-1000', $mapped['quote_number']);
        $this->assertSame('replied', $mapped['state']);
        $this->assertSame('2026-08-15T00:00:00+00:00', $mapped['expiration_date']);
        $this->assertSame('EUR', $mapped['currency']);
        $this->assertSame(['gross' => 119.0, 'net' => 100.0, 'tax_status' => 'gross'], $mapped['totals']);
        $this->assertSame('order-id', $mapped['order_id']);

        $this->assertCount(1, $mapped['line_items']);
        $this->assertSame(
            [
                'id' => 'line-item-id',
                'product_id' => 'product-id',
                'label' => 'Test Product',
                'quantity' => 10,
                'unit_price' => 11.9,
                'total_price' => 119.0,
                'requested_unit_price' => 9.5,
            ],
            $mapped['line_items'][0]
        );

        $this->assertSame(
            [
                ['comment' => 'please', 'author' => 'buyer', 'created_at' => '2026-07-27T10:00:00+00:00'],
                ['comment' => 'offered', 'author' => 'merchant', 'created_at' => '2026-07-27T11:00:00+00:00'],
            ],
            $mapped['comments']
        );
    }

    public function testMapExposesNullExpirationDate(): void
    {
        $quote = $this->quoteStub(expirationDate: null, lineItems: null, comments: null);

        $mapped = (new QuoteMapper())->map($quote);

        $this->assertArrayHasKey('expiration_date', $mapped);
        $this->assertNull($mapped['expiration_date']);
        $this->assertSame([], $mapped['line_items']);
        $this->assertSame([], $mapped['comments']);
    }

    private function quoteStub(
        ?\DateTimeImmutable $expirationDate = new \DateTimeImmutable('2026-08-15T00:00:00+00:00'),
        ?array $lineItems = [],
        ?array $comments = []
    ): object {
        if ($lineItems === []) {
            $lineItems = [
                new class {
                    public function getId(): string
                    {
                        return 'line-item-id';
                    }

                    public function getProductId(): string
                    {
                        return 'product-id';
                    }

                    public function getLabel(): string
                    {
                        return 'Test Product';
                    }

                    public function getQuantity(): int
                    {
                        return 10;
                    }

                    public function getUnitPrice(): float
                    {
                        return 11.9;
                    }

                    public function getTotalPrice(): float
                    {
                        return 119.0;
                    }

                    public function getRequestedPrice(): ?float
                    {
                        return 9.5;
                    }
                },
            ];
        }

        if ($comments === []) {
            $comments = [
                $this->commentStub('please', 'customer-id', new \DateTimeImmutable('2026-07-27T10:00:00+00:00')),
                $this->commentStub('offered', null, new \DateTimeImmutable('2026-07-27T11:00:00+00:00')),
            ];
        }

        return new class($expirationDate, $lineItems, $comments) {
            public function __construct(
                private readonly ?\DateTimeImmutable $expirationDate,
                private readonly ?array $lineItems,
                private readonly ?array $comments,
            ) {
            }

            public function getId(): string
            {
                return 'quote-id';
            }

            public function getQuoteNumber(): string
            {
                return 'QT-1000';
            }

            public function getStateMachineState(): object
            {
                return new class {
                    public function getTechnicalName(): string
                    {
                        return 'replied';
                    }
                };
            }

            public function getExpirationDate(): ?\DateTimeInterface
            {
                return $this->expirationDate;
            }

            public function getCurrency(): object
            {
                return new class {
                    public function getIsoCode(): string
                    {
                        return 'EUR';
                    }
                };
            }

            public function getAmountTotal(): float
            {
                return 119.0;
            }

            public function getAmountNet(): float
            {
                return 100.0;
            }

            public function getTaxStatus(): string
            {
                return 'gross';
            }

            public function getLineItems(): ?array
            {
                return $this->lineItems;
            }

            public function getComments(): ?array
            {
                return $this->comments;
            }

            public function getOrderId(): ?string
            {
                return 'order-id';
            }
        };
    }

    private function commentStub(string $text, ?string $customerId, \DateTimeImmutable $createdAt): object
    {
        return new class($text, $customerId, $createdAt) {
            public function __construct(
                private readonly string $text,
                private readonly ?string $customerId,
                private readonly \DateTimeImmutable $createdAt,
            ) {
            }

            public function getComment(): string
            {
                return $this->text;
            }

            public function getCustomerId(): ?string
            {
                return $this->customerId;
            }

            public function getCreatedAt(): \DateTimeImmutable
            {
                return $this->createdAt;
            }
        };
    }
}
