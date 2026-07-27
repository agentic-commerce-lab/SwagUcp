# B2B Quote Capability (`com.shopware.quote`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose Shopware Commercial B2B Request-for-Quote as a protocol-neutral UCP capability: discovery entry, self-served OpenAPI schema, five buyer operations under `/ucp/quotes`, per-customer agent authorization.

**Architecture:** SwagUcp gets a soft (runtime-detected, never composer) dependency on SwagCommercial. A `QuoteFeatureService` gates everything (class existence + `License::get('QUOTE_MANAGEMENT-8702512')`). A `QuoteBuyerService` resolves the buyer claim to a customer, enforces the new `swag_ucp_agent_authorization` record and the `QUOTE_MANAGEMENT` customer flag, and builds an impersonated `SalesChannelContext`. A `QuoteService` facade invokes the Commercial Store API route services **in-process** (injected `on-invalid="null"` as `?object` so the plugin compiles/runs without Commercial). `QuoteMapper` shapes UCP JSON. `QuoteController` follows `CheckoutController` (agent auth → buyer resolution → service → map).

**Tech Stack:** PHP 8.1+, Shopware 6.7 plugin, plain services.xml DI, PHPUnit 9/10 (`tests/Unit`, `tests/Integration`).

## Global Constraints (from quote_goal.log)

- No composer dependency on SwagCommercial; runtime detection only.
- Capability name `com.shopware.quote`, version `2026-07-27`; never `dev.ucp.*`.
- `customer_specific_features.features` is a **map** `{"QUOTE_MANAGEMENT": true}` — reuse Commercial's `CustomerSpecificFeatureService::isAllowed()`.
- Buyer agents never hold customer credentials; buyer claim = `buyer.email` (POST body) / `buyer_email` (GET query).
- Error codes (403 unless noted): `unauthorized`, `buyer_not_found` (404), `agent_not_authorized`, `quote_not_enabled_for_buyer`; missing Commercial → routes 404; foreign quote id → 404 (Commercial's own customer-scoped load).
- Requested prices → `quote_line_item.requestedPrice` (per unit), never `priceDefinition`.
- No changes to existing checkout behavior; discovery entry is additive.
- Commercial touch points verified against `/Users/sebastian/projects/SwagCommercial`:
  - `POST /store-api/quote/request` → `...\Domain\CartToQuote\QuoteRequestRoute::request($context, ?RequestDataBag)` → response `->getQuote()`, creates **draft** from context cart, deletes cart.
  - `...\Domain\LineItem\QuoteLineItemRoute::edit($quoteId, $lineItemId, $context, RequestDataBag{requestedPrice})` — allowed in `draft` and `replied`.
  - `...\Domain\State\QuoteSendRequestRoute::sendRequest($context, $id, ?RequestDataBag{comment})` — draft→open.
  - `...\Domain\QuoteAccounting\QuoteLoadRoute::load($id, $context, Criteria)` — filters `customerId` + `salesChannelId`, throws `QuoteException::quoteNotFound` (404-ish) otherwise.
  - `...\Domain\State\QuoteRequestChangeRoute::requestChange($context, $id, RequestDataBag{comment})` — only from `replied`.
  - `...\Domain\State\QuoteDeclineRoute::decline($context, $id, RequestDataBag{comment})` — only from `replied`.
  - `...\Domain\QuoteToOrder\QuoteOrderRoute::order($context, RequestDataBag, $id)` — accept = place order, response `->getOrder()`.
  - States: draft, open, replied, in_review, accepted, declined, expired, change_requested, withdrawn, cancelled.
  - `Shopware\Commercial\B2B\CustomerSpecificFeatures\Domain\CustomerSpecificFeature\CustomerSpecificFeatureService::isAllowed(?string $customerId, string $feature): bool`.
  - `Shopware\Commercial\Licensing\License::get(string $toggle): string|bool|int` (static, `false` = unlicensed).

---

### Task 1: Constants + QuoteFeatureService + discovery entry

**Files:** Modify `src/Ucp.php`; Create `src/Service/QuoteFeatureService.php`; Modify `src/Controller/DiscoveryController.php`, `src/Resources/config/services.xml`; Test `tests/Unit/Service/QuoteFeatureServiceTest.php`.

**Interfaces:** `Ucp::CAPABILITY_QUOTE = 'com.shopware.quote'`, `Ucp::QUOTE_VERSION = '2026-07-27'`. `QuoteFeatureService::isAvailable(): bool` — `class_exists('Shopware\Commercial\B2B\QuoteManagement\QuoteManagement')` AND `License::get('QUOTE_MANAGEMENT-8702512') !== false` (License FQCN referenced as string, guarded by `class_exists`). Constructor takes optional override closure for tests. DiscoveryController adds, only when available, `services['com.shopware.quote'] = ['version' => Ucp::QUOTE_VERSION, 'spec' => $baseUrl.'/ucp/schemas/quote.openapi.json', 'rest' => ['schema' => ..., 'endpoint' => $baseUrl.'/ucp/quotes']]`.

- [ ] Failing unit test: without Commercial classes `isAvailable()` is false; with injected availability-check closure returning true it is true.
- [ ] Implement; wire services.xml; run `vendor/bin/phpunit --testsuite Unit`.
- [ ] Commit.

### Task 2: Agent authorization record (migration + DAL entity)

**Files:** Create `src/Migration/Migration1785000000CreateSwagUcpAgentAuthorizationTable.php`, `src/Entity/AgentAuthorization/AgentAuthorizationDefinition.php`, `src/Entity/AgentAuthorization/AgentAuthorizationEntity.php`; Modify `src/Resources/config/services.xml`.

**Interfaces:** table `swag_ucp_agent_authorization` (`id` BINARY(16) PK, `customer_id` BINARY(16) NOT NULL FK→customer ON DELETE CASCADE, `agent_domain` VARCHAR(255) NOT NULL, `key_id` VARCHAR(255) NULL, `revoked_at` DATETIME(3) NULL, `created_at`/`updated_at` DATETIME(3), UNIQUE(customer_id, agent_domain)). DAL definition entity name `swag_ucp_agent_authorization` → Admin API CRUD (`/api/swag-ucp-agent-authorization`) is the v1 admin surface; revocation = PATCH `revokedAt`.

- [ ] Write definition + entity + migration; register definition in services.xml with `shopware.entity.definition` tag.
- [ ] Unit test: definition exposes expected fields (entity name, required customerId/agentDomain).
- [ ] Commit.

### Task 3: QuoteBuyerService (resolve buyer → authorize agent → gate → impersonated context)

**Files:** Create `src/Service/QuoteBuyerService.php`, `src/Service/QuoteAccessException.php`; Modify `src/Service/AgentAuthorizationService.php` (additive `extractAgentDomain(?string $header): ?string`), `src/Resources/config/services.xml`; Test `tests/Unit/Service/QuoteBuyerServiceTest.php`.

**Interfaces:** `QuoteBuyerService::resolveContext(?string $agentDomain, ?string $email, ?string $customerNumber, SalesChannelContext $anonymous): SalesChannelContext`. Throws `QuoteAccessException` carrying `(code, httpStatus)`: `buyer_not_found`/404 → `agent_not_authorized`/403 → `quote_not_enabled_for_buyer`/403, in that order. Deps: `customer.repository`, `swag_ucp_agent_authorization.repository`, Commercial `CustomerSpecificFeatureService` as `?object` (`on-invalid="null"`), `AbstractSalesChannelContextFactory`. Authorization requires a record with matching `agentDomain` and `revokedAt IS NULL`. Context: `factory->create(Uuid::randomHex(), $salesChannelId, [SalesChannelContextService::CUSTOMER_ID => $customerId])`.

- [ ] Failing unit tests: unknown email → buyer_not_found; no/revoked record → agent_not_authorized; record ok but feature service says no (or is null) → quote_not_enabled_for_buyer; all pass → context factory called with customer id.
- [ ] Implement; run Unit suite; commit.

### Task 4: QuoteService facade + QuoteMapper

**Files:** Create `src/Service/QuoteService.php`, `src/Mapper/QuoteMapper.php`; Modify `src/Resources/config/services.xml`; Tests `tests/Unit/Mapper/QuoteMapperTest.php`, `tests/Unit/Service/QuoteServiceTest.php`.

**Interfaces:** All Commercial route deps typed `?object`, injected by FQCN service id with `on-invalid="null"`. `QuoteService`:
- `create(array $payload, SalesChannelContext $ctx): object` — resolve products (id or product_number via `product.repository`), `LineItemFactoryRegistry` + `CartService::add` into ctx cart, `QuoteRequestRoute::request` → draft, per-line `requestedPrice` via `QuoteLineItemRoute::edit` (match quote line items by productId), `QuoteSendRequestRoute::sendRequest` with comment, reload via `read()`.
- `read(string $id, SalesChannelContext $ctx): object` — `QuoteLoadRoute::load` with Criteria assocs `lineItems`, `comments`, `stateMachineState`, `currency`.
- `counter(string $id, array $payload, SalesChannelContext $ctx): object` — optional line `requestedPrice` edits then `requestChange` with comment; reload.
- `accept(string $id, SalesChannelContext $ctx): object` (returns order) ; `decline(string $id, ?string $comment, SalesChannelContext $ctx): object` (reload quote).
`QuoteMapper::map(object $quote): array` — id, quote_number, state, expiration_date (always present, null allowed), currency ISO, totals `{gross, net, tax_status}`, line_items `[{id, product_id, label, quantity, unit_price, total_price, requested_unit_price}]` (unit prices per unit, currency of quote, gross/net per tax_status), comments, order_id.

- [ ] Failing mapper test with anonymous-class quote stub (dynamic getters make this possible without Commercial).
- [ ] QuoteService unit test: throws quote_unavailable RuntimeException when routes are null; create() orchestration happy-path with stub objects.
- [ ] Implement both; Unit suite green; commit.

### Task 5: QuoteController + OpenAPI schema + wiring

**Files:** Create `src/Controller/QuoteController.php`, `src/Resources/schemas/quote.openapi.json`; Modify `src/Resources/config/services.xml`; Test `tests/Unit/Schema/QuoteOpenApiSchemaTest.php`.

**Interfaces:** Routes (all `auth_required => false`, storefront scope, like CheckoutController): `POST /ucp/quotes`, `GET /ucp/quotes/{id}`, `POST /ucp/quotes/{id}/counter`, `POST /ucp/quotes/{id}/accept`, `POST /ucp/quotes/{id}/decline`, `GET /ucp/schemas/quote.openapi.json`. Every action: `QuoteFeatureService->isAvailable()` else 404 `quote_unavailable`; then agent auth (existing `authorizeRequest`) else 403 `unauthorized`; then `QuoteBuyerService::resolveContext` (buyer from body `buyer.email`/`buyer.customer_number` or query `buyer_email`); then QuoteService; map Commercial `ShopwareHttpException`/`HttpException` to its own error code + status (expired accept → 400 `CHECKOUT__QUOTE_CANNOT_PLACE_ORDER` passthrough; quoteNotFound → 404). Error envelope identical to CheckoutController. Schema documents: state machine transition table (`x-state-machine`), who-may-act, expiration semantics, price semantics (per-unit, gross/net via `tax_status`), polling (`x-polling-interval-seconds: 300`), auth requirements + all error codes.

- [ ] Schema unit test: file is valid JSON, OpenAPI 3.1, has the 5 paths, error codes enum, x-state-machine covering all buyer-visible states.
- [ ] Implement controller + schema; Unit suite green; commit.

### Task 6: Integration tests + acceptance sweep

**Files:** Create `tests/Integration/Controller/QuoteControllerTest.php`; Modify `CHANGELOG.md`.

- Without Commercial (always runnable): discovery JSON has no `com.shopware.quote`; `POST /ucp/quotes` and schema route → 404. Guarded with kernel-availability skip like existing integration tests.
- With Commercial (skip via `class_exists` otherwise): full loop create→read→counter→accept; negative cases (unknown buyer 404, unauthorized agent 403, unflagged customer 403, foreign quote 404, revoked authorization 403).
- [ ] Run full Unit suite + php -l on all new files; update CHANGELOG; commit.

## Self-Review notes

- Spec coverage: discovery (T1), agent-authorization record + revocation (T2), buyer gating/errors (T3), operations + Commercial authority (T4), schema contracts + endpoints (T5), acceptance criteria/tests (T6). Merchant-side servicing intentionally absent (out of scope).
- Type consistency: `QuoteAccessException(code, status)` consumed by controller; `?object` facade types used consistently; `resolveContext` returns core `SalesChannelContext`.
