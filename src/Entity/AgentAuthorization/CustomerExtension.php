<?php

declare(strict_types=1);

namespace SwagUcp\Entity\AgentAuthorization;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Reverse side of AgentAuthorizationDefinition's customer association
 * (required for a valid DAL schema; also lets the Admin API load a
 * customer's agent authorizations in one request).
 */
class CustomerExtension extends EntityExtension
{
    public function getEntityName(): string
    {
        return CustomerDefinition::ENTITY_NAME;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToManyAssociationField(
                'swagUcpAgentAuthorizations',
                AgentAuthorizationDefinition::class,
                'customer_id'
            ))->addFlags(new CascadeDelete())
        );
    }
}
