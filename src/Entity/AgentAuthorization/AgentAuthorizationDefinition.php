<?php

declare(strict_types=1);

namespace SwagUcp\Entity\AgentAuthorization;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Managed via the Admin API (CRUD on /api/swag-ucp-agent-authorization);
 * revocation = set revokedAt. This is the minimal v1 admin surface.
 */
class AgentAuthorizationDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_ucp_agent_authorization';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentAuthorizationEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            (new StringField('agent_domain', 'agentDomain'))->addFlags(new Required()),
            new StringField('key_id', 'keyId'),
            new DateTimeField('revoked_at', 'revokedAt'),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),
        ]);
    }
}
