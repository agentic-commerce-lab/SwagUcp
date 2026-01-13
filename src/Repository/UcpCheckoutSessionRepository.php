<?php

declare(strict_types=1);

namespace SwagUcp\Repository;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use SwagUcp\Entity\UcpCheckoutSession;

class UcpCheckoutSessionRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function save(UcpCheckoutSession $session, Context $context): void
    {
        $data = [
            'id' => $session->getId(),
            'cart_token' => $session->getCartToken(),
            'status' => $session->getStatus(),
            'capabilities' => json_encode($session->getCapabilities()),
            'checkout_data' => json_encode($session->getCheckoutData()),
            'order_id' => $session->getOrderId(),
            'expires_at' => $session->getExpiresAt()->format('Y-m-d H:i:s'),
            'created_at' => $session->getCreatedAt()->format('Y-m-d H:i:s'),
        ];

        $existing = $this->get($session->getId(), $context);
        
        if ($existing) {
            $this->connection->update(
                'ucp_checkout_session',
                $data,
                ['id' => $session->getId()]
            );
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

    private function hydrate(array $row): UcpCheckoutSession
    {
        $session = new UcpCheckoutSession();
        $session->setId($row['id']);
        $session->setCartToken($row['cart_token']);
        $session->setStatus($row['status']);
        $session->setCapabilities(json_decode($row['capabilities'], true));
        $session->setCheckoutData(json_decode($row['checkout_data'], true));
        $session->setOrderId($row['order_id']);
        $session->setExpiresAt(new \DateTime($row['expires_at']));
        $session->setCreatedAt(new \DateTime($row['created_at']));

        return $session;
    }
}
