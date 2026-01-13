<?php

declare(strict_types=1);

namespace SwagUcp\Entity;

class UcpCheckoutSession
{
    public string $status;

    /**
     * @var array<string, mixed>
     */
    public array $checkoutData;

    public ?string $orderId = null;

    /**
     * @param list<array<string, mixed>> $capabilities
     * @param array<string, mixed> $checkoutData
     */
    public function __construct(
        public readonly string $id,
        public readonly string $cartToken,
        string $status,
        public readonly array $capabilities,
        array $checkoutData,
        public readonly \DateTimeInterface $expiresAt,
        public readonly \DateTimeInterface $createdAt,
        ?string $orderId = null,
    ) {
        $this->status = $status;
        $this->checkoutData = $checkoutData;
        $this->orderId = $orderId;
    }
}
