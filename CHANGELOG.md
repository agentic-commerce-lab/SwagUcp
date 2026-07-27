# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- `com.shopware.quote` vendor capability: buyer-facing B2B Request-for-Quote flow for any UCP agent
  - Advertised in `/.well-known/ucp` only when SwagCommercial is active and Quote Management is licensed (soft runtime dependency, no composer requirement)
  - Endpoints: `POST /ucp/quotes`, `GET /ucp/quotes/{id}`, `POST /ucp/quotes/{id}/counter`, `POST /ucp/quotes/{id}/accept`, `POST /ucp/quotes/{id}/decline`
  - Self-served OpenAPI schema at `/ucp/schemas/quote.openapi.json` documenting the quote state machine, expiration and price semantics, and machine-readable error codes
  - Per-customer agent authorization records (`swag_ucp_agent_authorization`, manageable/revocable via Admin API) plus `QUOTE_MANAGEMENT` customer-specific-feature gating
  - Quote operations run in a server-side customer context; buyer agents never hold Shopware credentials

## [1.0.0] - 2026-01-11

### Added
- Initial release of UCP Integration for Shopware 6
- Discovery endpoint at `/.well-known/ucp`
- Complete Checkout API implementation
- Capability negotiation service
- Schema validation service
- Payment handler service
- Signature verification service
- Key management service
- Database migration for checkout sessions
- Unit tests for services
- Integration tests for API endpoints

### Security
- EC P-256 signing keys for webhook verification
- Request signature verification
- Schema-based request validation
