# Changelog

All notable changes to this project will be documented in this file.

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
