<?php

declare(strict_types=1);

namespace SwagUcp\Entity\AgentAuthorization;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Association customer <-> authorized agent platform.
 *
 * A quote request is only allowed when the verified agent platform (domain
 * from the UCP-Agent profile URL) has an unrevoked record for the resolved
 * customer. Deliberately protocol-neutral: external identity layers (signed
 * mandates etc.) map onto this record from outside the plugin.
 */
class AgentAuthorizationEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;

    protected ?CustomerEntity $customer = null;

    protected string $agentDomain;

    protected ?string $keyId = null;

    protected ?\DateTimeInterface $revokedAt = null;

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getAgentDomain(): string
    {
        return $this->agentDomain;
    }

    public function setAgentDomain(string $agentDomain): void
    {
        $this->agentDomain = $agentDomain;
    }

    public function getKeyId(): ?string
    {
        return $this->keyId;
    }

    public function setKeyId(?string $keyId): void
    {
        $this->keyId = $keyId;
    }

    public function getRevokedAt(): ?\DateTimeInterface
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeInterface $revokedAt): void
    {
        $this->revokedAt = $revokedAt;
    }
}
