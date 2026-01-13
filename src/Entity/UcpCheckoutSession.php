<?php

declare(strict_types=1);

namespace SwagUcp\Entity;

class UcpCheckoutSession
{
    private string $id;
    private string $cartToken;
    private string $status;
    private array $capabilities;
    private array $checkoutData;
    private ?string $orderId = null;
    private \DateTimeInterface $expiresAt;
    private \DateTimeInterface $createdAt;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getCartToken(): string
    {
        return $this->cartToken;
    }

    public function setCartToken(string $cartToken): void
    {
        $this->cartToken = $cartToken;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function getCheckoutData(): array
    {
        return $this->checkoutData;
    }

    public function setCheckoutData(array $checkoutData): void
    {
        $this->checkoutData = $checkoutData;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeInterface $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
