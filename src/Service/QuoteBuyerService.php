<?php

declare(strict_types=1);

namespace SwagUcp\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Resolves a buyer claim (email / customer number) to a customer, enforces the
 * per-customer agent authorization record and the QUOTE_MANAGEMENT customer
 * flag, and builds a SalesChannelContext for that customer so quote operations
 * behave exactly as if the customer acted themselves (contract pricing, rules,
 * quote ownership). Buyer agents never hold Shopware credentials.
 */
class QuoteBuyerService
{
    /** Key in customer_specific_features.features (a map: {"QUOTE_MANAGEMENT": true}) */
    public const CUSTOMER_FEATURE = 'QUOTE_MANAGEMENT';

    /**
     * @param object|null $customerSpecificFeatureService Commercial CustomerSpecificFeatureService
     *                                                    (soft dependency, null when SwagCommercial is absent)
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $agentAuthorizationRepository,
        private readonly ?object $customerSpecificFeatureService,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
    ) {
    }

    /**
     * Enforcement order is part of the capability contract:
     * buyer_not_found (404) -> agent_not_authorized (403) -> quote_not_enabled_for_buyer (403).
     *
     * @throws QuoteAccessException
     */
    public function resolveContext(
        ?string $agentDomain,
        ?string $email,
        ?string $customerNumber,
        SalesChannelContext $anonymousContext
    ): SalesChannelContext {
        $customerId = $this->resolveCustomerId($email, $customerNumber, $anonymousContext);

        if ($customerId === null) {
            throw QuoteAccessException::buyerNotFound();
        }

        if ($agentDomain === null || !$this->isAgentAuthorized($customerId, $agentDomain, $anonymousContext)) {
            throw QuoteAccessException::agentNotAuthorized();
        }

        if (!$this->hasQuoteFeature($customerId)) {
            throw QuoteAccessException::quoteNotEnabledForBuyer();
        }

        return $this->createCustomerContext($customerId, $anonymousContext);
    }

    /**
     * OAuth path (dev.ucp.common.identity_linking): the validated access token
     * already proves agent identity AND the customer's consent - the grant is
     * the authorization. No buyer claim and no per-request
     * swag_ucp_agent_authorization record are needed; only commercial
     * eligibility (QUOTE_MANAGEMENT) still applies.
     *
     * @throws QuoteAccessException
     */
    public function resolveTokenContext(string $customerId, SalesChannelContext $anonymousContext): SalesChannelContext
    {
        if (!$this->isActiveCustomer($customerId, $anonymousContext)) {
            throw QuoteAccessException::buyerNotFound();
        }

        if (!$this->hasQuoteFeature($customerId)) {
            throw QuoteAccessException::quoteNotEnabledForBuyer();
        }

        return $this->createCustomerContext($customerId, $anonymousContext);
    }

    private function isActiveCustomer(string $customerId, SalesChannelContext $context): bool
    {
        try {
            $criteria = new Criteria([$customerId]);
            $criteria->addFilter(new EqualsFilter('active', true));

            return $this->customerRepository->searchIds($criteria, $context->getContext())->firstId() !== null;
        } catch (\Exception) {
            return false; // malformed customer id in the token: treat as unknown buyer
        }
    }

    private function createCustomerContext(string $customerId, SalesChannelContext $anonymousContext): SalesChannelContext
    {
        return $this->contextFactory->create(
            Uuid::randomHex(),
            $anonymousContext->getSalesChannelId(),
            [SalesChannelContextService::CUSTOMER_ID => $customerId]
        );
    }

    private function resolveCustomerId(?string $email, ?string $customerNumber, SalesChannelContext $context): ?string
    {
        if (($email === null || $email === '') && ($customerNumber === null || $customerNumber === '')) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        if ($email !== null && $email !== '') {
            $criteria->addFilter(new EqualsFilter('email', $email));
        }

        if ($customerNumber !== null && $customerNumber !== '') {
            $criteria->addFilter(new EqualsFilter('customerNumber', $customerNumber));
        }

        return $this->customerRepository->searchIds($criteria, $context->getContext())->firstId();
    }

    private function isAgentAuthorized(string $customerId, string $agentDomain, SalesChannelContext $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('agentDomain', strtolower($agentDomain)));
        $criteria->addFilter(new EqualsFilter('revokedAt', null));

        return $this->agentAuthorizationRepository->searchIds($criteria, $context->getContext())->getTotal() > 0;
    }

    private function hasQuoteFeature(string $customerId): bool
    {
        if ($this->customerSpecificFeatureService === null
            || !method_exists($this->customerSpecificFeatureService, 'isAllowed')) {
            return false;
        }

        return (bool) $this->customerSpecificFeatureService->isAllowed($customerId, self::CUSTOMER_FEATURE);
    }
}
