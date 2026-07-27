<?php

declare(strict_types=1);

namespace SwagUcp\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use SwagUcp\Entity\AgentAuthorization\AgentAuthorizationDefinition;
use SwagUcp\Entity\AgentAuthorization\AgentAuthorizationEntity;

class AgentAuthorizationTest extends TestCase
{
    public function testDefinitionBasics(): void
    {
        $definition = new AgentAuthorizationDefinition();

        $this->assertSame('swag_ucp_agent_authorization', $definition->getEntityName());
        $this->assertSame(AgentAuthorizationEntity::class, $definition->getEntityClass());
    }

    public function testEntityAccessors(): void
    {
        $entity = new AgentAuthorizationEntity();
        $entity->setCustomerId('customer-id');
        $entity->setAgentDomain('agent.example.com');
        $entity->setKeyId('key-1');

        $this->assertSame('customer-id', $entity->getCustomerId());
        $this->assertSame('agent.example.com', $entity->getAgentDomain());
        $this->assertSame('key-1', $entity->getKeyId());
        $this->assertNull($entity->getRevokedAt());

        $revokedAt = new \DateTimeImmutable('2026-07-27 12:00:00');
        $entity->setRevokedAt($revokedAt);
        $this->assertSame($revokedAt, $entity->getRevokedAt());
    }
}
