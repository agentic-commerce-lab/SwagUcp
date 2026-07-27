<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Facade over the Commercial Store API quote routes, invoked in-process in the
 * resolved customer's SalesChannelContext so the commercial plugin remains
 * authoritative for all quote logic (pricing, state machine, ownership).
 *
 * All Commercial dependencies are typed `object` and injected with
 * on-invalid="null" (soft dependency): this class compiles and instantiates
 * without SwagCommercial; QuoteFeatureService gates all callers.
 */
class QuoteService
{
    /**
     * @param object|null $quoteRequestRoute       Commercial QuoteRequestRoute (POST /store-api/quote/request)
     * @param object|null $quoteSendRequestRoute   Commercial QuoteSendRequestRoute (draft -> open)
     * @param object|null $quoteLineItemRoute      Commercial QuoteLineItemRoute (requestedPrice edits)
     * @param object|null $quoteLoadRoute          Commercial QuoteLoadRoute (customer-scoped read)
     * @param object|null $quoteRequestChangeRoute Commercial QuoteRequestChangeRoute (replied -> change_requested)
     * @param object|null $quoteDeclineRoute       Commercial QuoteDeclineRoute (replied -> declined)
     * @param object|null $quoteOrderRoute         Commercial QuoteOrderRoute (accept = place order)
     */
    public function __construct(
        private readonly ?object $quoteRequestRoute,
        private readonly ?object $quoteSendRequestRoute,
        private readonly ?object $quoteLineItemRoute,
        private readonly ?object $quoteLoadRoute,
        private readonly ?object $quoteRequestChangeRoute,
        private readonly ?object $quoteDeclineRoute,
        private readonly ?object $quoteOrderRoute,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly EntityRepository $productRepository,
    ) {
    }

    /**
     * Two-step RFQ: fill the context cart, convert to a draft quote, apply
     * per-line requested unit prices, then send (draft -> open).
     *
     * @param array<string, mixed> $payload {line_items: [{product_id?, product_number?, quantity, requested_unit_price?}], comment?}
     *
     * @return object the created quote (state open)
     */
    public function create(array $payload, SalesChannelContext $context): object
    {
        $this->ensureAvailable();

        $lines = $payload['line_items'] ?? [];
        if (!\is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException('line_items must be a non-empty array');
        }

        $requestedPrices = [];
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $lineItems = [];

        foreach ($lines as $line) {
            $productId = $this->resolveProductId($line, $context);
            $quantity = (int) ($line['quantity'] ?? 1);
            if ($quantity <= 0) {
                throw new \InvalidArgumentException('quantity must be a positive integer');
            }

            $lineItems[] = $this->lineItemFactory->create(
                ['type' => 'product', 'referencedId' => $productId, 'quantity' => $quantity],
                $context
            );

            if (isset($line['requested_unit_price']) && \is_numeric($line['requested_unit_price'])) {
                $requestedPrices[$productId] = (float) $line['requested_unit_price'];
            }
        }

        $this->cartService->add($cart, $lineItems, $context);

        $draft = $this->quoteRequestRoute->request($context)->getQuote();

        $this->applyRequestedPrices($draft, $requestedPrices, $context);

        $comment = trim((string) ($payload['comment'] ?? ''));
        $this->quoteSendRequestRoute->sendRequest($context, $draft->getId(), new RequestDataBag(['comment' => $comment]));

        return $this->read($draft->getId(), $context);
    }

    /**
     * Customer-scoped read: the Commercial route filters by customerId and
     * salesChannelId and throws its not-found exception otherwise, so foreign
     * quote ids yield 404 without confirming existence.
     */
    public function read(string $id, SalesChannelContext $context): object
    {
        $this->ensureAvailable();

        $criteria = new Criteria();
        $criteria->addAssociation('lineItems');
        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
        $criteria->addAssociation('comments');

        return $this->quoteLoadRoute->load($id, $context, $criteria)->getQuote();
    }

    /**
     * Counter-offer from state `replied`: optional new per-line requested unit
     * prices plus a request-change transition with comment.
     *
     * @param array<string, mixed> $payload {line_items?: [{id?, product_id?, requested_unit_price}], comment?}
     */
    public function counter(string $id, array $payload, SalesChannelContext $context): object
    {
        $this->ensureAvailable();

        $lines = $payload['line_items'] ?? [];
        if (\is_array($lines) && $lines !== []) {
            $quote = $this->read($id, $context);
            $this->applyCounterPrices($quote, $lines, $context);
        }

        $comment = trim((string) ($payload['comment'] ?? ''));
        $this->quoteRequestChangeRoute->requestChange($context, $id, new RequestDataBag(['comment' => $comment]));

        return $this->read($id, $context);
    }

    /**
     * Accepting is ordering in Shopware's model: executes the quote -> order
     * flow and returns the order entity.
     */
    public function accept(string $id, SalesChannelContext $context): object
    {
        $this->ensureAvailable();

        return $this->quoteOrderRoute->order($context, new RequestDataBag(), $id)->getOrder();
    }

    public function decline(string $id, ?string $comment, SalesChannelContext $context): object
    {
        $this->ensureAvailable();

        $this->quoteDeclineRoute->decline($context, $id, new RequestDataBag(['comment' => trim((string) $comment)]));

        return $this->read($id, $context);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function resolveProductId(array $line, SalesChannelContext $context): string
    {
        $productId = $line['product_id'] ?? null;
        if (\is_string($productId) && $productId !== '') {
            return $productId;
        }

        $productNumber = $line['product_number'] ?? null;
        if (\is_string($productNumber) && $productNumber !== '') {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));
            $id = $this->productRepository->searchIds($criteria, $context->getContext())->firstId();

            if ($id === null) {
                throw new \InvalidArgumentException(\sprintf('Unknown product number: %s', $productNumber));
            }

            return $id;
        }

        throw new \InvalidArgumentException('Each line item requires product_id or product_number');
    }

    /**
     * Requested prices go to quote_line_item.requestedPrice (per unit) via the
     * Commercial line item route - never into priceDefinition.
     *
     * @param array<string, float> $requestedPrices productId => requested unit price
     */
    private function applyRequestedPrices(object $quote, array $requestedPrices, SalesChannelContext $context): void
    {
        if ($requestedPrices === []) {
            return;
        }

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $price = $requestedPrices[$lineItem->getProductId()] ?? null;
            if ($price === null) {
                continue;
            }

            $this->quoteLineItemRoute->edit(
                $quote->getId(),
                $lineItem->getId(),
                $context,
                new RequestDataBag(['requestedPrice' => $price])
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function applyCounterPrices(object $quote, array $lines, SalesChannelContext $context): void
    {
        $byLineItemId = [];
        $byProductId = [];
        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $byLineItemId[$lineItem->getId()] = $lineItem;
            if ($lineItem->getProductId() !== null) {
                $byProductId[$lineItem->getProductId()] = $lineItem;
            }
        }

        foreach ($lines as $line) {
            if (!isset($line['requested_unit_price']) || !\is_numeric($line['requested_unit_price'])) {
                continue;
            }

            $lineItem = $byLineItemId[$line['id'] ?? ''] ?? $byProductId[$line['product_id'] ?? ''] ?? null;
            if ($lineItem === null) {
                throw new \InvalidArgumentException('Counter line item does not match any quote line item');
            }

            $this->quoteLineItemRoute->edit(
                $quote->getId(),
                $lineItem->getId(),
                $context,
                new RequestDataBag(['requestedPrice' => (float) $line['requested_unit_price']])
            );
        }
    }

    private function ensureAvailable(): void
    {
        if ($this->quoteRequestRoute === null
            || $this->quoteSendRequestRoute === null
            || $this->quoteLineItemRoute === null
            || $this->quoteLoadRoute === null
            || $this->quoteRequestChangeRoute === null
            || $this->quoteDeclineRoute === null
            || $this->quoteOrderRoute === null) {
            throw new \RuntimeException('Quote capability unavailable: SwagCommercial quote routes not registered');
        }
    }
}
