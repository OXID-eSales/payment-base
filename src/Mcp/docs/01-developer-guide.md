# MCP/ACP/UCP Developer Guide

## Overview

The payment-component provides a provider-agnostic framework for AI agent commerce via three complementary protocols:

| Protocol | Transport | Spec | Consumer |
|----------|-----------|------|----------|
| **MCP** (Model Context Protocol) | JSON-RPC 2.0 | `2025-06-18` | Anthropic Claude, OpenAI, open-source LLMs |
| **UCP** (Universal Commerce Protocol) | REST/JSON | `2026-01-11` | Google Shopping agents |
| **ACP** (Agent Commerce Protocol) | Internal | — | Shared backend for MCP and UCP |

Both MCP and UCP share the same ACP backend (`AbstractAcpCheckoutService`), so business logic is written once and exposed through two transport layers. Payment providers implement a thin adapter layer.

```
 MCP (JSON-RPC)          UCP (REST)
      │                      │
 McpController      UcpCheckoutController          ← provider module
      │                      │
 McpServer          UcpCheckoutRequestHandler
      │                      │
 ACP Tools ──────► AcpCheckoutServiceInterface
                         │
               AbstractAcpCheckoutService           ← payment-component
                         │
              completePayment() [abstract]
                         │
              ProviderCheckoutService               ← provider module
                         │
               PaymentAdapterInterface
```

Provider modules (e.g., Stripe, PayPal) implement:
1. **Controllers** — HTTP entry points that authenticate and emit events
2. **`AcpCheckoutServiceInterface`** — extends `AbstractAcpCheckoutService`, implements `createCheckout()` and `completePayment()`
3. **Payment confirmation** — provider-specific token validation and charge creation

## Authentication

All MCP and UCP endpoints require a Bearer token in the `Authorization` header:

```
Authorization: Bearer <configured-api-key>
```

Authentication flow (`McpAuthGuard`):

1. Extracts Bearer token from `$_SERVER['HTTP_AUTHORIZATION']`
2. Compares against configured key using `hash_equals()` (constant-time, timing-safe)
3. Derives stable agent ID: `agent_` + first 8 chars of `sha256(token)`
4. Returns `AuthResult::success(AgentContext)` or `AuthResult::failed(message)`

The expected token is injected via DI — provider modules supply the actual value from their configuration.

Failed authentication returns HTTP 401 with a JSON error body.

## MCP Protocol

The `McpServer` implements JSON-RPC 2.0 with protocol version `2025-06-18`.

### Supported Methods

| Method | Description |
|--------|-------------|
| `initialize` | Handshake — returns protocol version, capabilities, server info |
| `tools/list` | Returns all registered ACP tools with schemas |
| `tools/call` | Executes a named tool with arguments |

### Tool Registration

Tools implement `McpToolInterface` and are collected via Symfony's tagged iterator (`payment.mcp_tool`). Each tool provides:
- `getName(): string` — tool identifier
- `getDescription(): string` — human-readable description
- `getInputSchema(): array` — JSON Schema for arguments
- `execute(array $arguments, AgentContextInterface $agentContext): array` — tool logic

## ACP Tool Reference

The framework provides 6 checkout tools. All tools delegate to `AcpCheckoutServiceInterface`.

### `create_checkout`

Create a checkout session for the given items and buyer information.

```json
{
  "type": "object",
  "properties": {
    "items": {
      "type": "array",
      "description": "Products to purchase",
      "items": {
        "type": "object",
        "properties": {
          "id": { "type": "string", "description": "Product/article ID" },
          "quantity": { "type": "integer", "minimum": 1 }
        },
        "required": ["id", "quantity"]
      }
    },
    "buyer": {
      "type": "object",
      "description": "Buyer information",
      "properties": {
        "first_name": { "type": "string" },
        "last_name": { "type": "string" },
        "email": { "type": "string", "format": "email" },
        "phone_number": { "type": "string" }
      },
      "required": ["email"]
    },
    "fulfillment_address": {
      "type": "object",
      "description": "Shipping address",
      "properties": {
        "name": { "type": "string" },
        "line_one": { "type": "string" },
        "line_two": { "type": "string" },
        "city": { "type": "string" },
        "state": { "type": "string" },
        "country": { "type": "string", "description": "ISO 3166-1 alpha-2" },
        "postal_code": { "type": "string" }
      },
      "required": ["line_one", "city", "country", "postal_code"]
    },
    "currency": {
      "type": "string",
      "description": "ISO 4217 currency code",
      "default": "EUR"
    }
  },
  "required": ["items", "buyer"]
}
```

### `get_checkout`

Retrieve the current status of a checkout session.

```json
{
  "type": "object",
  "properties": {
    "checkout_id": { "type": "string", "description": "Checkout session ID" }
  },
  "required": ["checkout_id"]
}
```

### `update_checkout`

Update a checkout session with shipping selection or other options.

```json
{
  "type": "object",
  "properties": {
    "checkout_id": { "type": "string", "description": "Checkout session ID" },
    "selected_fulfillment_option_id": {
      "type": "string",
      "description": "Selected shipping/delivery option ID"
    }
  },
  "required": ["checkout_id"]
}
```

### `complete_checkout`

Complete checkout and process payment using a delegated payment token.

```json
{
  "type": "object",
  "properties": {
    "checkout_id": { "type": "string", "description": "Checkout session ID" },
    "payment_data": {
      "type": "object",
      "description": "Delegated payment credentials",
      "properties": {
        "token": {
          "type": "string",
          "description": "Delegated payment token from payment provider"
        },
        "provider": { "type": "string", "description": "Payment provider name" },
        "billing_address": {
          "type": "object",
          "properties": {
            "name": { "type": "string" },
            "line_one": { "type": "string" },
            "line_two": { "type": "string" },
            "city": { "type": "string" },
            "state": { "type": "string" },
            "country": { "type": "string" },
            "postal_code": { "type": "string" }
          }
        }
      },
      "required": ["token", "provider"]
    }
  },
  "required": ["checkout_id", "payment_data"]
}
```

### `cancel_checkout`

Cancel an active checkout session.

```json
{
  "type": "object",
  "properties": {
    "checkout_id": { "type": "string", "description": "Checkout session ID" }
  },
  "required": ["checkout_id"]
}
```

### `list_products`

Search and list available products in the shop catalog.

```json
{
  "type": "object",
  "properties": {
    "search": {
      "type": "string",
      "description": "Search query for product title or description"
    },
    "category_id": { "type": "string", "description": "Filter by category ID" },
    "limit": {
      "type": "integer",
      "description": "Maximum number of results",
      "default": 20, "minimum": 1, "maximum": 100
    },
    "offset": {
      "type": "integer",
      "description": "Pagination offset",
      "default": 0, "minimum": 0
    }
  }
}
```

## UCP REST Protocol

UCP uses standard REST conventions with required headers. Provider modules implement the HTTP controllers; the framework provides request validation and response formatting.

### Required Headers

| Header | Required | Description |
|--------|----------|-------------|
| `Authorization` | Yes | `Bearer <token>` |
| `Request-Id` | Yes | Unique request identifier for tracing |
| `UCP-Agent` | No | Agent profile URL: `profile="https://..."` |
| `Idempotency-Key` | No | Idempotency key for POST requests |
| `Content-Type` | Yes | `application/json` |

### Routes

Provider modules register controllers for these REST patterns:

| Method | Path | Status | Description |
|--------|------|--------|-------------|
| GET | `/profile` | 200 | UCP profile with capabilities and payment handlers |
| POST | `/checkout` | 201 | Create a new checkout session |
| GET | `/checkout/{id}` | 200 | Retrieve checkout status |
| PUT | `/checkout/{id}` | 200 | Update checkout (e.g., shipping selection) |
| POST | `/checkout/{id}/complete` | 200 | Complete checkout with payment token |
| POST | `/checkout/{id}/cancel` | 200 | Cancel checkout session |

### UCP Profile Response

```json
{
  "ucp_version": "2026-01-11",
  "services": {
    "dev.ucp.shopping": {
      "rest": { "endpoint": "https://shop.example.com/api/ucp/checkout" }
    }
  },
  "capabilities": [
    { "name": "dev.ucp.shopping.checkout", "version": "2026-01-11" }
  ],
  "payment": {
    "handlers": [
      { "id": "provider-name", "spec": "https://provider.example.com/ucp-handler", "version": "2026-01-11" }
    ]
  }
}
```

### UCP Checkout Session Response

```json
{
  "id": "contract_abc123",
  "status": "incomplete",
  "currency": "eur",
  "line_items": [
    {
      "id": "li_1",
      "product_id": "dc5ffdf380e15674b56dd562a7cb6aec",
      "quantity": 1,
      "unit_price": 1000,
      "total": 1000
    }
  ],
  "totals": {
    "subtotal": 1000,
    "tax": 190,
    "total": 1190
  }
}
```

### UCP Error Response

```json
{
  "error": {
    "type": "not_found",
    "message": "Checkout session not found",
    "param": "checkout_id"
  }
}
```

## Contract State Mapping

Both ACP and UCP formatters map internal `PaymentContract` states to protocol-specific status names:

| Contract State | ACP Status | UCP Status |
|----------------|------------|------------|
| `draft` | `not_ready_for_payment` | `incomplete` |
| `not_finished` | `not_ready_for_payment` | `incomplete` |
| `pending` | `ready_for_payment` | `incomplete` |
| `authorized` | `ready_for_payment` | `ready_for_complete` |
| `ready_to_commit` | `completed` | `completed` |
| `committed` | `completed` | `completed` |
| `fulfilled` | `completed` | `completed` |
| `cancelled` | `canceled` | `canceled` |
| `expired` | `canceled` | `canceled` |
| `failed` | `canceled` | `canceled` |

Note: ACP uses American spelling `canceled` (one `l`).

## Event-Driven Architecture

Controllers are thin — they authenticate, parse input, and emit events. All business logic lives in handlers. This decouples the transport layer from the domain logic.

### MCP Flow

```
Controller::handleRequest()
  → authenticates via McpAuthGuard
  → reads php://input (raw JSON-RPC)
  → dispatches McpRequestReceivedEvent(context)
  → McpRequestHandler::handle()
      → McpServer::handleJsonRpc()
          → routes to initialize / tools/list / tools/call
          → Tool::execute() → AcpCheckoutService methods
  → reads mcpResponse from context
  → outputs JSON
```

### UCP Flow

```
Controller::handleRequest()
  → authenticates via McpAuthGuard
  → validates headers via UcpRequestValidator
  → extracts HTTP method, path segments, body
  → dispatches UcpCheckoutRequestEvent(context)
  → Handler::handle()
      → routes based on method + path segments
      → AcpCheckoutService methods
  → reads httpStatusCode + responseData from context
  → outputs JSON with status code
```

### Key Events

| Event | Emitted By | Handled By |
|-------|------------|------------|
| `McpRequestReceivedEvent` | MCP Controller | `McpRequestHandler` → `McpServer` |
| `UcpCheckoutRequestEvent` | UCP Controller | Provider's UCP handler |

## Delegated Payment Token Flow

The ACP `complete_checkout` tool accepts a provider-specific delegated payment token via `payment_data.token`. The flow:

```
1. Agent obtains token from payment provider    →  provider-specific token
2. Agent calls complete_checkout                →  passes token as payment_data.token
3. AbstractAcpCheckoutService::completeCheckout →  validates contract, calls completePayment()
4. Provider::completePayment()                  →  confirms payment with provider SDK
5. PaymentAuthorizedEvent dispatched            →  triggers contract state transition
6. Contract → READY_TO_COMMIT                   →  order creation begins
7. ACP order response returned                  →  includes order permalink
```

The `completePayment()` method is abstract in `AbstractAcpCheckoutService` — each provider implements its own token confirmation logic.

## Example MCP JSON-RPC Payloads

### Initialize

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-06-18",
    "clientInfo": { "name": "my-agent", "version": "1.0.0" }
  }
}
```

Response:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2025-06-18",
    "capabilities": { "tools": {} },
    "serverInfo": { "name": "oxid-payment-mcp", "version": "1.0.0" }
  }
}
```

### tools/list

```json
{ "jsonrpc": "2.0", "id": 2, "method": "tools/list" }
```

Response:
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "tools": [
      {
        "name": "create_checkout",
        "description": "Create an ACP checkout session for the given items and buyer information",
        "inputSchema": { "..." }
      },
      { "name": "get_checkout", "..." },
      { "name": "update_checkout", "..." },
      { "name": "complete_checkout", "..." },
      { "name": "cancel_checkout", "..." },
      { "name": "list_products", "..." }
    ]
  }
}
```

### tools/call — create_checkout

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "create_checkout",
    "arguments": {
      "items": [{ "id": "product_123", "quantity": 2 }],
      "buyer": {
        "first_name": "Max",
        "last_name": "Mustermann",
        "email": "max@example.com"
      },
      "fulfillment_address": {
        "line_one": "Musterstr. 1",
        "city": "Berlin",
        "country": "DE",
        "postal_code": "10115"
      },
      "currency": "EUR"
    }
  }
}
```

### tools/call — complete_checkout

```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "method": "tools/call",
  "params": {
    "name": "complete_checkout",
    "arguments": {
      "checkout_id": "contract_abc123",
      "payment_data": {
        "token": "provider_delegated_token_xxx",
        "provider": "my-provider"
      }
    }
  }
}
```

### tools/call — list_products

```json
{
  "jsonrpc": "2.0",
  "id": 5,
  "method": "tools/call",
  "params": {
    "name": "list_products",
    "arguments": {
      "search": "t-shirt",
      "limit": 10
    }
  }
}
```

## Building a Provider Module

To add MCP/ACP/UCP support to a new payment provider:

### 1. Extend `AbstractAcpCheckoutService`

Implement `createCheckout()` and the abstract `completePayment()`:

```php
class MyProviderCheckoutService extends AbstractAcpCheckoutService
{
    public function createCheckout(array $arguments, AgentContextInterface $agentContext): array
    {
        // Create contract from ACP arguments
        // Dispatch provider-specific event
        // Return $this->formatter->formatCheckout($contract)
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array {
        // Extract token from $paymentData['token']
        // Confirm payment with provider SDK
        // Dispatch PaymentAuthorizedEvent
        // Return $this->formatter->formatOrder($contract, $permalink)
    }
}
```

### 2. Implement `AcpProductServiceInterface`

```php
class MyProviderProductService implements AcpProductServiceInterface
{
    public function listProducts(array $filters = []): array { /* ... */ }
    public function getProduct(string $productId): ?array { /* ... */ }
}
```

### 3. Register Controllers

Register MCP and UCP controllers in `metadata.php`:

```php
'controllers' => [
    'myprovider_mcp'         => MyMcpController::class,
    'myprovider_ucp'         => MyUcpCheckoutController::class,
    'myprovider_ucp_profile' => MyUcpProfileController::class,
],
```

### 4. Wire Services (services.yaml)

```yaml
# Bind ACP interface to your implementation
AcpCheckoutServiceInterface:
  class: MyProviderCheckoutService

# Bind product service
AcpProductServiceInterface:
  class: MyProviderProductService

# Configure auth guard with your API key parameter
McpAuthGuardInterface:
  class: McpAuthGuard
  arguments:
    $expectedToken: '%my_provider.agent_api_key%'

# Tag tools for auto-discovery
CreateCheckoutTool:
  tags: [{ name: payment.mcp_tool }]
# ... (all 6 tools)
```

## Source File Index

### `src/Mcp/` — 30 files

| File | Description |
|------|-------------|
| `McpServer.php` | JSON-RPC 2.0 router — handles `initialize`, `tools/list`, `tools/call` |
| `McpServerInterface.php` | Interface for MCP server |
| `McpToolInterface.php` | Interface each tool implements (`getName`, `getInputSchema`, `execute`) |
| `AgentContextInterface.php` | Interface for authenticated agent context |
| `AgentContext.php` | Value object carrying authenticated agent ID and token |
| **Auth/** | |
| `McpAuthGuard.php` | Bearer token authentication with `hash_equals()` |
| `McpAuthGuardInterface.php` | Interface for auth guard |
| `AuthResult.php` | Success/failure result with optional `AgentContext` |
| **Handler/** | |
| `McpRequestHandler.php` | Event handler — routes `McpRequestReceivedEvent` to `McpServer` |
| **Event/** | |
| `McpRequestReceivedEvent.php` | Event carrying raw JSON-RPC body in `EventContext` |
| **Http/** | |
| `HttpClientInterface.php` | `post()` and `get()` interface for outbound HTTP |
| `HttpClientResponse.php` | Readonly response VO with `isSuccessful()` and `failed()` factory |
| **Acp/** | |
| `AcpCheckoutServiceInterface.php` | Contract for checkout operations (create/get/update/complete/cancel) |
| `AbstractAcpCheckoutService.php` | Shared logic for get/update/cancel/complete; abstract `completePayment()` |
| `AcpResponseFormatter.php` | Formats `PaymentContract` into ACP response (status mapping, minor units) |
| `AcpResponseFormatterInterface.php` | Interface for response formatter |
| `AcpProductServiceInterface.php` | Interface for product listing |
| **Acp/Tool/** | |
| `CreateCheckoutTool.php` | MCP tool — creates checkout session |
| `GetCheckoutTool.php` | MCP tool — retrieves checkout status |
| `UpdateCheckoutTool.php` | MCP tool — updates shipping selection |
| `CompleteCheckoutTool.php` | MCP tool — completes checkout with payment token |
| `CancelCheckoutTool.php` | MCP tool — cancels active checkout |
| `ListProductsTool.php` | MCP tool — searches product catalog |
| **Ucp/** | |
| `UcpProfile.php` | UCP profile response (version `2026-01-11`, capabilities, payment handlers) |
| `UcpProfileInterface.php` | Interface for UCP profile |
| `UcpCapability.php` | Readonly VO for UCP capability negotiation |
| `UcpCapabilityNegotiationService.php` | Filters business capabilities against agent capabilities |
| `UcpRequestValidator.php` | Validates `Request-Id` header, extracts `UCP-Agent` profile URL |
| `UcpResponseFormatter.php` | Formats contract into UCP checkout session shape |
| `UcpResponseFormatterInterface.php` | Interface for UCP formatter |
