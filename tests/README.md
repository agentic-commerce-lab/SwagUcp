# SwagUcp Test Suite

This document describes all available tests for the SwagUcp plugin and how to run them.

## Overview

The test suite consists of three categories:

| Category | Location | Purpose |
|----------|----------|---------|
| **Unit Tests** | `tests/Unit/` | Test individual services in isolation |
| **Integration Tests** | `tests/Integration/` | Test complete UCP flows and protocol compliance |
| **Live Flow Tests** | `tests/run_live_flow_test.sh` | End-to-end HTTP tests against running instance |

## Prerequisites

- Docker container with Shopware 6 running
- SwagUcp plugin installed and activated
- PHPUnit available via `vendor/bin/phpunit`

## Running Tests

### All Unit Tests

```bash
# Inside the plugin directory
cd /var/www/html/custom/plugins/SwagUcp

# Run all unit tests
vendor/bin/phpunit tests/Unit --testdox
```

### Individual Test Suites

#### 1. Key Management Service Tests

Tests cryptographic key generation, PEM/JWK conversion, and key persistence.

```bash
vendor/bin/phpunit tests/Unit/Service/KeyManagementServiceTest.php --testdox
```

**What it tests:**
- EC P-256 key pair generation
- PEM to JWK conversion
- JWK to PEM conversion
- Invalid JWK handling
- Key ID generation

#### 2. Signature Verification Service Tests

Tests ES256 signing and verification for JWTs and Detached JWS.

```bash
vendor/bin/phpunit tests/Unit/Service/SignatureVerificationServiceTest.php --testdox
```

**What it tests:**
- JWT creation and verification
- Detached JWS (RFC 7797) signatures
- Request signature creation/verification
- Tampered body detection
- Wrong key rejection
- Merchant authorization signing

#### 3. Security Protocol Compliance Tests

Dedicated tests to verify UCP security specification compliance.

```bash
vendor/bin/phpunit tests/Unit/Service/SecurityProtocolComplianceTest.php --testdox
```

**What it tests:**
- ES256 algorithm usage
- `kid` header inclusion
- Detached JWS format (header..signature)
- JWK field completeness (kty, crv, x, y, kid, alg, use)
- Key discovery by `kid`
- Unsupported algorithm rejection
- Base64url encoding (no padding)
- Cross-key interoperability

#### 4. UCP Agent Simulation Tests

Simulates a complete UCP flow from an AI agent's perspective.

```bash
vendor/bin/phpunit tests/Integration/UcpAgentSimulationTest.php --testdox
```

**What it tests:**
- Discovery endpoint parsing
- Capability negotiation
- Checkout session lifecycle (create → update → complete)
- Merchant authorization (AP2) signing/verification
- Webhook signature creation and verification
- Order update webhooks (payment_confirmed, shipped)
- Tampered webhook rejection

### Live HTTP Flow Test

Tests the actual HTTP endpoints against a running Shopware instance.

```bash
# From the host machine (not inside container)
./tests/run_live_flow_test.sh
```

**What it tests:**
- `GET /.well-known/ucp` - Discovery endpoint
- `POST /ucp/checkout-sessions` - Create checkout
- `GET /ucp/checkout-sessions/{id}` - Retrieve checkout
- `PUT /ucp/checkout-sessions/{id}` - Update checkout
- `POST /ucp/checkout-sessions/{id}/cancel` - Cancel checkout
- Signing key validity (EC P-256, ES256)

## Test Output Examples

### Unit Test Output

```
Key Management Service (SwagUcp\Tests\Unit\Service\KeyManagementService)
 ✔ Generate ec p256 key pair
 ✔ Pem to jwk conversion
 ✔ Jwk to pem conversion
 ✔ Invalid jwk returns null
 ✔ Get key id returns default when not set

Signature Verification Service (SwagUcp\Tests\Unit\Service\SignatureVerificationService)
 ✔ Create and verify jwt
 ✔ Verify jwt with wrong key fails
 ✔ Create request signature
 ✔ Verify request signature
 ✔ Verify request signature with tampered body fails
 ...
```

### Agent Simulation Output

```
======================================================================
UCP AGENT SIMULATION - COMPLETE FLOW
======================================================================

STEP 1: DISCOVERY
----------------------------------------
✓ Discovered business profile
  - UCP Version: 2026-01-11
  - Capabilities: 3
  - Signing Keys: 1

STEP 2: CAPABILITY NEGOTIATION
----------------------------------------
✓ Negotiated capabilities
  - dev.ucp.shopping.checkout (v2026-01-11)
  - dev.ucp.shopping.fulfillment (v2026-01-11)
  - dev.ucp.shopping.order (v2026-01-11)

...

======================================================================
UCP FLOW COMPLETED SUCCESSFULLY
======================================================================
```

### Live Flow Test Output

```
======================================================================
UCP LIVE FLOW INTEGRATION TEST
======================================================================

[STEP 1] DISCOVERY
--------------------------------------
✓ Discovery endpoint returned valid UCP profile
→ UCP Version: 2026-01-11
→ Capabilities: 2
✓ Signing keys present: 1
✓ Signing key uses ES256 algorithm

[STEP 2] CREATE CHECKOUT SESSION
--------------------------------------
✓ Created checkout session: chk_9d42538bf7889837...
✓ Initial status: incomplete
→ Currency: EUR
→ Total: €118.97

...

======================================================================
ALL TESTS PASSED
======================================================================
```

## Test Coverage

### Security Components

| Component | Test File | Coverage |
|-----------|-----------|----------|
| Key Generation | `KeyManagementServiceTest.php` | EC P-256, PEM/JWK |
| Signing | `SignatureVerificationServiceTest.php` | ES256, JWT, Detached JWS |
| Protocol Compliance | `SecurityProtocolComplianceTest.php` | RFC 7797, UCP Spec |

### API Endpoints

| Endpoint | Test File | Coverage |
|----------|-----------|----------|
| `/.well-known/ucp` | `run_live_flow_test.sh` | Profile, Keys |
| `/ucp/checkout-sessions` | `run_live_flow_test.sh` | CRUD Operations |
| Webhooks | `UcpAgentSimulationTest.php` | Signature Flow |

## Troubleshooting

### Tests fail with "Class not found"

Run composer autoload:
```bash
composer dump-autoload
```

### HTTP tests return 403/400

Ensure the Shopware instance is properly configured:
- Sales channel domain is set to `localhost`
- Plugin is activated
- Cache is cleared: `bin/console cache:clear`

### "Risky test" warnings

PHPUnit shows "risky" warnings for tests with `echo` output. This is expected for verbose test output and does not indicate failure.

## Adding New Tests

1. Create test file in appropriate directory (`Unit/` or `Integration/`)
2. Extend `PHPUnit\Framework\TestCase`
3. Use `@test` annotation or `test` prefix for test methods
4. Mock external dependencies for unit tests

Example:
```php
<?php

namespace SwagUcp\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

class MyServiceTest extends TestCase
{
    /** @test */
    public function itDoesSomething(): void
    {
        $this->assertTrue(true);
    }
}
```

## CI/CD Integration

For automated testing in CI pipelines:

```yaml
# Example GitHub Actions
- name: Run Unit Tests
  run: |
    docker exec container vendor/bin/phpunit tests/Unit --testdox
    
- name: Run Integration Tests
  run: |
    docker exec container vendor/bin/phpunit tests/Integration --testdox
```
