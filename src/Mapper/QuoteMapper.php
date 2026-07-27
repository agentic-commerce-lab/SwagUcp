<?php

declare(strict_types=1);

namespace SwagUcp\Mapper;

/**
 * Maps a Commercial QuoteEntity to the UCP quote representation documented in
 * quote.openapi.json. Typed against `object` because SwagCommercial is a soft
 * dependency - all getters are resolved at runtime.
 */
class QuoteMapper
{
    /**
     * @param object $quote Shopware\Commercial\B2B\QuoteManagement\Entity\Quote\QuoteEntity
     *
     * @return array<string, mixed>
     */
    public function map(object $quote): array
    {
        return [
            'id' => $quote->getId(),
            'quote_number' => $quote->getQuoteNumber(),
            'state' => $quote->getStateMachineState()?->getTechnicalName(),
            // Always exposed, even when null: agents must read this to act before expiry
            'expiration_date' => $this->formatDate($quote->getExpirationDate()),
            'currency' => $quote->getCurrency()?->getIsoCode(),
            'totals' => [
                'gross' => $quote->getAmountTotal(),
                'net' => $quote->getAmountNet(),
                // 'gross' or 'net' - tells agents which total is authoritative for payment
                'tax_status' => $quote->getTaxStatus(),
            ],
            'line_items' => $this->mapLineItems($quote->getLineItems()),
            'comments' => $this->mapComments($quote->getComments()),
            'order_id' => $quote->getOrderId(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapLineItems(?iterable $lineItems): array
    {
        if ($lineItems === null) {
            return [];
        }

        $mapped = [];
        foreach ($lineItems as $lineItem) {
            $mapped[] = [
                'id' => $lineItem->getId(),
                'product_id' => $lineItem->getProductId(),
                'label' => $lineItem->getLabel(),
                'quantity' => $lineItem->getQuantity(),
                // Per-unit amounts in the quote currency; gross/net follows totals.tax_status
                'unit_price' => $lineItem->getUnitPrice(),
                'total_price' => $lineItem->getTotalPrice(),
                'requested_unit_price' => $lineItem->getRequestedPrice(),
            ];
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapComments(?iterable $comments): array
    {
        if ($comments === null) {
            return [];
        }

        $mapped = [];
        foreach ($comments as $comment) {
            $mapped[] = [
                'comment' => $comment->getComment(),
                'author' => $comment->getCustomerId() !== null ? 'buyer' : 'merchant',
                'created_at' => $this->formatDate($comment->getCreatedAt()),
            ];
        }

        return $mapped;
    }

    private function formatDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
