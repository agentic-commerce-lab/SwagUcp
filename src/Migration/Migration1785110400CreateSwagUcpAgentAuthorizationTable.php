<?php

declare(strict_types=1);

namespace SwagUcp\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1785110400CreateSwagUcpAgentAuthorizationTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785110400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `swag_ucp_agent_authorization` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `agent_domain` VARCHAR(255) NOT NULL,
                `key_id` VARCHAR(255) NULL,
                `revoked_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.swag_ucp_agent_authorization.customer_domain` (`customer_id`, `agent_domain`),
                CONSTRAINT `fk.swag_ucp_agent_authorization.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `swag_ucp_agent_authorization`');
    }
}
