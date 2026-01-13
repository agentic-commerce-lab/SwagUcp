#!/bin/bash
#
# UCP Live Flow Integration Test
# 
# This script simulates a complete UCP flow from an agent's perspective,
# testing all endpoints and validating the protocol compliance.
#
# Prerequisites:
# - Docker container 'competent_cerf' running with Shopware
# - SwagUcp plugin installed and activated
#

set -e

# Run curl from the host machine, not inside container
BASE_URL="http://localhost"

echo "======================================================================"
echo "UCP LIVE FLOW INTEGRATION TEST"
echo "======================================================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

success() { echo -e "${GREEN}✓${NC} $1"; }
error() { echo -e "${RED}✗${NC} $1"; exit 1; }
info() { echo -e "${BLUE}→${NC} $1"; }

# ========================================
# STEP 1: DISCOVERY
# ========================================
echo "[STEP 1] DISCOVERY"
echo "--------------------------------------"

# Discovery does not require X-Requested-With
DISCOVERY=$(curl -s "${BASE_URL}/.well-known/ucp")

# Validate discovery response
VERSION=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(json.load(sys.stdin)['ucp']['version'])" 2>/dev/null || echo "")
if [ "$VERSION" != "2026-01-11" ]; then
    error "Discovery failed - incorrect version: $VERSION"
fi
success "Discovery endpoint returned valid UCP profile"
info "UCP Version: $VERSION"

CAPABILITIES=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['ucp']['capabilities']))" 2>/dev/null || echo "0")
info "Capabilities: $CAPABILITIES"

SIGNING_KEYS=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['signing_keys']))" 2>/dev/null || echo "0")
if [ "$SIGNING_KEYS" -eq "0" ]; then
    error "No signing keys found in profile"
fi
success "Signing keys present: $SIGNING_KEYS"

KEY_ALG=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(json.load(sys.stdin)['signing_keys'][0]['alg'])" 2>/dev/null || echo "")
if [ "$KEY_ALG" != "ES256" ]; then
    error "Signing key algorithm is not ES256: $KEY_ALG"
fi
success "Signing key uses ES256 algorithm"
echo ""

# ========================================
# STEP 2: CREATE CHECKOUT SESSION
# ========================================
echo "[STEP 2] CREATE CHECKOUT SESSION"
echo "--------------------------------------"

CREATE_REQUEST='{
  "line_items": [
    {
      "item": {
        "id": "live-test-product",
        "title": "Live Test Product",
        "price": 4999
      },
      "quantity": 2
    }
  ]
}'

CHECKOUT=$(curl -s -X POST "${BASE_URL}/ucp/checkout-sessions" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "$CREATE_REQUEST")

CHECKOUT_ID=$(echo "$CHECKOUT" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])" 2>/dev/null || echo "")
if [ -z "$CHECKOUT_ID" ]; then
    error "Failed to create checkout session"
fi
success "Created checkout session: $CHECKOUT_ID"

STATUS=$(echo "$CHECKOUT" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null || echo "")
if [ "$STATUS" != "incomplete" ]; then
    error "Initial status should be 'incomplete', got: $STATUS"
fi
success "Initial status: $STATUS"

CURRENCY=$(echo "$CHECKOUT" | python3 -c "import sys,json; print(json.load(sys.stdin)['currency'])" 2>/dev/null || echo "")
info "Currency: $CURRENCY"

TOTAL=$(echo "$CHECKOUT" | python3 -c "import sys,json; totals=json.load(sys.stdin)['totals']; print([t['amount'] for t in totals if t['type']=='total'][0])" 2>/dev/null || echo "0")
info "Total: €$(echo "scale=2; $TOTAL/100" | bc)"
echo ""

# ========================================
# STEP 3: GET CHECKOUT SESSION
# ========================================
echo "[STEP 3] GET CHECKOUT SESSION"
echo "--------------------------------------"

GET_CHECKOUT=$(curl -s "${BASE_URL}/ucp/checkout-sessions/${CHECKOUT_ID}" \
  -H "X-Requested-With: XMLHttpRequest")

GET_ID=$(echo "$GET_CHECKOUT" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])" 2>/dev/null || echo "")
if [ "$GET_ID" != "$CHECKOUT_ID" ]; then
    error "GET returned wrong checkout ID"
fi
success "GET checkout returned correct session"
echo ""

# ========================================
# STEP 4: UPDATE CHECKOUT SESSION
# ========================================
echo "[STEP 4] UPDATE CHECKOUT SESSION"
echo "--------------------------------------"

UPDATE_REQUEST='{
  "buyer": {
    "email": "live-test@example.com",
    "name": "Live Test Customer"
  },
  "fulfillment": {
    "address": {
      "name": "Live Test Customer",
      "line1": "Test Street 123",
      "city": "Berlin",
      "postal_code": "10115",
      "country": "DE"
    },
    "methods": [
      {
        "type": "shipping",
        "groups": [
          {
            "options": [
              {"id": "express", "name": "Express", "price": 1500},
              {"id": "standard", "name": "Standard", "price": 500}
            ],
            "selected_option_id": "express"
          }
        ]
      }
    ]
  }
}'

UPDATED=$(curl -s -X PUT "${BASE_URL}/ucp/checkout-sessions/${CHECKOUT_ID}" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "$UPDATE_REQUEST")

NEW_STATUS=$(echo "$UPDATED" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null || echo "")
if [ "$NEW_STATUS" != "ready_for_complete" ]; then
    error "Updated status should be 'ready_for_complete', got: $NEW_STATUS"
fi
success "Updated status: $NEW_STATUS"

BUYER_EMAIL=$(echo "$UPDATED" | python3 -c "import sys,json; print(json.load(sys.stdin)['buyer']['email'])" 2>/dev/null || echo "")
success "Buyer email: $BUYER_EMAIL"

NEW_TOTAL=$(echo "$UPDATED" | python3 -c "import sys,json; totals=json.load(sys.stdin)['totals']; print([t['amount'] for t in totals if t['type']=='total'][0])" 2>/dev/null || echo "0")
info "Updated total (with shipping): €$(echo "scale=2; $NEW_TOTAL/100" | bc)"
echo ""

# ========================================
# STEP 5: CANCEL CHECKOUT (instead of complete - no real payment)
# ========================================
echo "[STEP 5] CANCEL CHECKOUT SESSION"
echo "--------------------------------------"

CANCELLED=$(curl -s -X POST "${BASE_URL}/ucp/checkout-sessions/${CHECKOUT_ID}/cancel" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest")

FINAL_STATUS=$(echo "$CANCELLED" | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])" 2>/dev/null || echo "")
if [ "$FINAL_STATUS" != "canceled" ]; then
    error "Final status should be 'canceled', got: $FINAL_STATUS"
fi
success "Final status: $FINAL_STATUS"
echo ""

# ========================================
# STEP 6: VERIFY SIGNING KEYS ARE VALID
# ========================================
echo "[STEP 6] VERIFY SIGNING KEYS"
echo "--------------------------------------"

KEY_KTY=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(json.load(sys.stdin)['signing_keys'][0]['kty'])" 2>/dev/null || echo "")
KEY_CRV=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(json.load(sys.stdin)['signing_keys'][0]['crv'])" 2>/dev/null || echo "")
KEY_KID=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(json.load(sys.stdin)['signing_keys'][0]['kid'])" 2>/dev/null || echo "")
KEY_USE=$(echo "$DISCOVERY" | python3 -c "import sys,json; print(json.load(sys.stdin)['signing_keys'][0]['use'])" 2>/dev/null || echo "")

if [ "$KEY_KTY" != "EC" ]; then error "Key type must be EC"; fi
if [ "$KEY_CRV" != "P-256" ]; then error "Key curve must be P-256"; fi
if [ "$KEY_USE" != "sig" ]; then error "Key use must be sig"; fi

success "Key Type: $KEY_KTY"
success "Curve: $KEY_CRV"
success "Key ID: $KEY_KID"
success "Usage: $KEY_USE"
echo ""

# ========================================
# SUMMARY
# ========================================
echo "======================================================================"
echo "ALL TESTS PASSED"
echo "======================================================================"
echo ""
echo "Summary:"
echo "  ✓ Discovery endpoint with valid UCP profile"
echo "  ✓ ES256 signing keys published in profile"
echo "  ✓ Checkout session creation (status: incomplete)"
echo "  ✓ Checkout session retrieval"
echo "  ✓ Checkout session update (status: ready_for_complete)"
echo "  ✓ Checkout session cancellation (status: canceled)"
echo ""
echo "UCP Protocol Version: 2026-01-11"
echo "Cryptographic Algorithm: ES256 (ECDSA P-256 + SHA-256)"
echo ""
