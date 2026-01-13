<?php

declare(strict_types=1);

namespace SwagUcp\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1699000000CreateUcpCheckoutSessionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1699000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `ucp_checkout_session` (
                `id` VARCHAR(255) NOT NULL PRIMARY KEY,
                `cart_token` VARCHAR(255) NOT NULL,
                `status` VARCHAR(50) NOT NULL,
                `capabilities` JSON NOT NULL,
                `checkout_data` JSON NOT NULL,
                `order_id` VARCHAR(255) NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                INDEX `idx_cart_token` (`cart_token`),
                INDEX `idx_status` (`status`),
                INDEX `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `ucp_checkout_session`');
    }
}
