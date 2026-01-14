<?php

declare(strict_types=1);

namespace SwagUcp\Repository;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use SwagUcp\Entity\UcpCheckoutSession;

class UcpCheckoutSessionRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function save(UcpCheckoutSession $session, Context $context): void
    {
        $data = [
            'id' => $session->id,
            'cart_token' => $session->cartToken,
            'status' => $session->status,
            'capabilities' => json_encode($session->capabilities, \JSON_THROW_ON_ERROR),
            'checkout_data' => json_encode($session->checkoutData, \JSON_THROW_ON_ERROR),
            'order_id' => $session->orderId,
            'expires_at' => $session->expiresAt->format('Y-m-d H:i:s'),
            'created_at' => $session->createdAt->format('Y-m-d H:i:s'),
        ];

        $existing = $this->get($session->id, $context);

        if ($existing) {
            $this->connection->update('ucp_checkout_session', $data, ['id' => $session->id]);
        } else {
            $this->connection->insert('ucp_checkout_session', $data);
        }
    }

    public function get(string $id, Context $context): ?UcpCheckoutSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ucp_checkout_session WHERE id = ?',
            [$id]
        );

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UcpCheckoutSession
    {
        return new UcpCheckoutSession(
            id: $row['id'],
            cartToken: $row['cart_token'],
            status: $row['status'],
            capabilities: json_decode($row['capabilities'], true, 512, \JSON_THROW_ON_ERROR),
            checkoutData: json_decode($row['checkout_data'], true, 512, \JSON_THROW_ON_ERROR),
            expiresAt: new \DateTimeImmutable($row['expires_at']),
            createdAt: new \DateTimeImmutable($row['created_at']),
            orderId: $row['order_id'],
        );
    }
}
