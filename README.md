# Shopware UCP Integration

Universal Commerce Protocol (UCP) integration for Shopware 6.

## Installation

1. Copy this plugin to `custom/plugins/SwagUcp/` in your Shopware installation
2. Install dependencies:
   ```bash
   composer install
   ```
3. Install and activate the plugin:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagUcp
   ```
4. Run migrations:
   ```bash
   bin/console database:migrate --all SwagUcp
   ```
5. Clear cache:
   ```bash
   bin/console cache:clear
   ```

## Configuration

Configure the plugin in Shopware Admin:
- **Settings → System → Plugins → SwagUcp**

### All Configuration Options

#### UCP Protocol Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| **UCP Protocol Version** | Text | `2026-01-11` | The UCP protocol version this shop supports. Used in discovery profile and API responses. |

#### Security Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| **Enable Agent Whitelist** | Boolean | `false` | If enabled, only agents from whitelisted domains can use the UCP endpoints. Recommended for production. |
| **Whitelisted Agent Domains** | Textarea | (empty) | One domain per line. Supports wildcards (e.g., `*.openai.com`). If empty and whitelist is enabled, known AI platforms are allowed by default. |
| **Require Agent Signature** | Boolean | `false` | If enabled, all requests must include a valid `Request-Signature` header signed by the agent's private key. Provides cryptographic proof of request origin. |

#### Signing Keys

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| **Signing Key ID** | Text | (auto-generated) | Unique identifier for your signing key. Published in the `kid` field of JWK. Auto-generated as `shopware_ucp_<random>` if empty. |
| **Signing Public Key (PEM)** | Textarea | (auto-generated) | EC P-256 public key in PEM format. Published at `/.well-known/ucp` for webhook signature verification. Auto-generated if empty. |
| **Signing Private Key (PEM)** | Password | (auto-generated) | EC P-256 private key in PEM format. Used to sign outgoing webhooks. **Keep this secret!** Auto-generated if empty. |

#### Advanced Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| **Checkout Session TTL** | Integer | `30` | How long checkout sessions remain valid, in minutes. Expired sessions cannot be completed. |
| **Enable Webhooks** | Boolean | `true` | Send signed webhooks to platforms when order status changes (e.g., payment confirmed, shipped). |

### Signing Keys: How It Works

> ⚠️ **No hardcoded keys!** Each installation generates unique cryptographic keys.

#### Automatic Key Generation (Recommended for Most Users)

**You don't need to do anything!** When the plugin is first accessed:

1. The plugin checks if signing keys exist in the configuration
2. If no keys are found, it automatically generates:
   - A random Key ID: `shopware_ucp_<16-char-hex>`
   - An EC P-256 key pair using PHP's OpenSSL extension
3. Keys are stored in Shopware's SystemConfig (database) and persist permanently

To trigger key generation, simply access `https://your-shop.com/.well-known/ucp` after installation.

#### Manual Key Generation (Advanced Users)

If you prefer to generate your own keys (e.g., for key rotation or compliance requirements):

```bash
# Generate EC P-256 private key
openssl ecparam -genkey -name prime256v1 -noout -out private.pem

# Extract public key
openssl ec -in private.pem -pubout -out public.pem

# View the keys
cat private.pem  # Copy to "Signing Private Key" field
cat public.pem   # Copy to "Signing Public Key" field
```

Then in Shopware Admin:
1. Go to **Settings → System → Plugins → SwagUcp**
2. Paste the private key into **Signing Private Key (PEM)**
3. Paste the public key into **Signing Public Key (PEM)**
4. Set a unique **Signing Key ID** (e.g., `myshop_2026_01`)
5. Save and clear cache

#### Key Rotation

To rotate keys without downtime:
1. Generate new keys using the commands above
2. Update the configuration with new keys and a new Key ID
3. The new public key will immediately be published at `/.well-known/ucp`
4. Platforms will fetch the new key on their next request

#### Verify Your Keys

Check that your keys are published correctly:
```bash
curl https://your-shop.com/.well-known/ucp | jq '.signing_keys'
```

Expected output:
```json
[
  {
    "kid": "shopware_ucp_abc123...",
    "kty": "EC",
    "crv": "P-256",
    "x": "...",
    "y": "...",
    "use": "sig",
    "alg": "ES256"
  }
]
```

## Security Model

### How to Restrict Access to Authorized AI Agents

The plugin provides multiple layers of security:

#### 1. Domain Whitelist (Recommended)

Enable "Agent Whitelist" and configure allowed domains:

```
api.openai.com
generativelanguage.googleapis.com
api.anthropic.com
api.cohere.ai
```

Agents must send a `UCP-Agent` header with their profile URL:
```
UCP-Agent: UCP/2026-01-11 profile="https://api.openai.com/.well-known/ucp"
```

The plugin extracts the domain and checks against the whitelist.

#### 2. Request Signature Verification (High Security)

Enable "Require Agent Signature" for cryptographic verification:

1. Agent signs the request body with their private key
2. Sends signature in `Request-Signature` header (Detached JWS)
3. Plugin fetches agent's public keys from their `/.well-known/ucp`
4. Verifies the signature matches the request body

This ensures:
- ✅ Only authorized agents can call the endpoints
- ✅ Requests cannot be tampered with
- ✅ Non-repudiation (agent cannot deny sending request)

#### 3. Known AI Platforms (Default Whitelist)

If whitelist is enabled but no domains configured, these are automatically allowed:

| Platform | Domain |
|----------|--------|
| OpenAI | `api.openai.com` |
| Google Gemini | `generativelanguage.googleapis.com` |
| Anthropic | `api.anthropic.com` |
| Cohere | `api.cohere.ai` |
| Mistral | `api.mistral.ai` |
| Amazon Bedrock | `inference.aws.amazon.com` |
| Together AI | `api.together.xyz` |
| Perplexity | `api.perplexity.ai` |

### Security Configuration Examples

#### Development (Open Access)
```
Agent Whitelist: ❌ Disabled
Require Signature: ❌ Disabled
```

#### Production (Whitelist Only)
```
Agent Whitelist: ✅ Enabled
Whitelisted Domains: api.openai.com, api.anthropic.com
Require Signature: ❌ Disabled
```

#### High Security (Whitelist + Signatures)
```
Agent Whitelist: ✅ Enabled
Whitelisted Domains: api.openai.com
Require Signature: ✅ Enabled
```

## Features

### Discovery
- UCP profile available at `/.well-known/ucp`
- Automatic capability negotiation
- Payment handler discovery
- JWK signing keys published for webhook verification

### Checkout API
- `POST /ucp/checkout-sessions` - Create checkout
- `GET /ucp/checkout-sessions/{id}` - Get checkout
- `PUT /ucp/checkout-sessions/{id}` - Update checkout
- `POST /ucp/checkout-sessions/{id}/complete` - Complete checkout
- `POST /ucp/checkout-sessions/{id}/cancel` - Cancel checkout

### Webhook Signing

Outgoing webhooks (order updates) are signed using `Request-Signature` header:
```
Request-Signature: eyJhbGciOiJFUzI1NiIsImtpZCI6InNob3B3YXJlX3VjcF8yMDI2In0..MEUCIQDx...
```

Platforms can verify using the public key from `/.well-known/ucp`.

## Testing

### Run Unit Tests
```bash
vendor/bin/phpunit tests/Unit --testdox
```

### Run Integration Tests
```bash
vendor/bin/phpunit tests/Integration --testdox
```

### Run Live Flow Test
```bash
./tests/run_live_flow_test.sh
```

See `tests/README.md` for detailed test documentation.

## API Usage Examples

### Discovery
```bash
curl https://your-shop.com/.well-known/ucp
```

### Create Checkout (with Agent Header)
```bash
curl -X POST https://your-shop.com/ucp/checkout-sessions \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "UCP-Agent: UCP/2026-01-11 profile=\"https://api.openai.com/.well-known/ucp\"" \
  -d '{
    "line_items": [
      {
        "item": {
          "id": "product-123",
          "title": "Test Product",
          "price": 1000
        },
        "quantity": 1
      }
    ]
  }'
```

### With Request Signature (High Security)
```bash
curl -X POST https://your-shop.com/ucp/checkout-sessions \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "UCP-Agent: UCP/2026-01-11 profile=\"https://agent.example.com/.well-known/ucp\"" \
  -H "Request-Signature: eyJhbGciOiJFUzI1NiIsImtpZCI6ImFnZW50X2tleV8xIn0..SIGNATURE" \
  -d '{"line_items": [...]}'
```

## Checklist for Merchants

Before going live:

- [ ] Enable Agent Whitelist in plugin settings
- [ ] Configure allowed agent domains (or leave empty for known platforms)
- [ ] Consider enabling "Require Agent Signature" for high-value transactions
- [ ] Verify your signing keys are generated (check `/.well-known/ucp`)
- [ ] Test the integration with your AI agent partner
- [ ] Ensure HTTPS is configured
- [ ] Review webhook endpoints are secured

## Troubleshooting

### "Domain not in whitelist" error
- Add the agent's domain to the whitelist
- Or disable whitelist for development

### "Invalid request signature" error
- Agent must send valid `Request-Signature` header
- Public keys must be published at agent's `/.well-known/ucp`

### Keys not appearing in discovery
- Clear Shopware cache: `bin/console cache:clear`
- Keys auto-generate on first request

## License

Apache License 2.0
