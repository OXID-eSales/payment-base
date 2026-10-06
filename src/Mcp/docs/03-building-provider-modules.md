# Building Provider Modules

This guide describes how to add MCP/ACP/UCP agent commerce support to a new payment provider module.

## What the Framework Provides

The payment-component gives you:

- **MCP server** — JSON-RPC 2.0 protocol handling, tool registry, request routing
- **ACP tools** — 6 ready-made tools (create/get/update/complete/cancel checkout, list products)
- **UCP infrastructure** — profile negotiation, request validation, response formatting
- **Authentication** — `McpAuthGuard` with constant-time token comparison
- **Response formatters** — ACP and UCP formatters that convert `PaymentContract` to protocol-specific shapes
- **Abstract checkout service** — shared logic for get/update/cancel/complete with contract validation

## What You Implement

Your provider module must supply:

1. **3 controllers** — HTTP entry points for MCP, UCP checkout, and UCP profile
2. **Checkout service** — extends `AbstractAcpCheckoutService` with `createCheckout()` and `completePayment()`
3. **Product service** — implements `AcpProductServiceInterface`
4. **UCP request handler** — routes REST requests to the checkout service
5. **UCP checkout event** — event class for the UCP controller to dispatch
6. **HTTP client** — implements `HttpClientInterface` (for outbound calls if needed)
7. **DI wiring** — `services.yaml` entries binding interfaces to your implementations

## Step-by-Step Implementation

### 1. Create the Checkout Service

Extend `AbstractAcpCheckoutService`. Since Sprint 15 / S7 (GRAPH-QL) the base class runs `create_checkout` on the
shared **headless path**, so you implement only what is provider-specific:

- `paymentId()` — the payment id your checkouts are paid with (e.g. `oe_payments_stripe_wallet`)
- `providerName()` — how your provider names itself in contracts (`stripe`, `mollie`, `paypal`)
- `completePayment()` — charge the delegated token with your SDK, then `$this->commitPaid(...)` and return the order response

`createCheckout()` has a default: the ACP `buyer` becomes a shop user (`GuestUserResolverInterface`: existing account
by e-mail or a guest account with `fulfillment_address`), the `items` a persisted `oxuserbaskets` row paying with
`paymentId()` (`UserBasketFactoryInterface`), and the contract is opened through the same chain as every checkout
(`ContractOpeningServiceInterface`: contract → `ContractDraftCompletedEvent` → early order → PENDING). No PSP session is
started — the agent pays in `complete_checkout`. Override it only when your provider needs more.

```php
namespace MyVendor\MyProvider\Mcp\Service;

use OxidEsales\PaymentBase\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

class MyProviderCheckoutService extends AbstractAcpCheckoutService
{
    protected function paymentId(): string
    {
        return 'oe_payments_myprovider';
    }

    protected function providerName(): string
    {
        return 'myprovider';
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array {
        $charge = $this->sdk->chargeDelegatedToken($paymentData['token'], $contract->getAmount(), $contract->getCurrency());
        if (!$charge->succeeded()) {
            return $this->formatter->validationError($charge->errorMessage(), 'payment_data.token');
        }

        // Same commit the webhooks and the headless return use: idempotent,
        // refuses closed contracts and mismatched amounts.
        $outcome = $this->commitPaid($contract, $charge->authorizationId(), $charge->orderId(), $charge->amount(), $charge->currency());
        if (!$outcome->isSettled()) {
            return $this->formatter->validationError('Payment confirmed but the order could not be committed: ' . $outcome->reason);
        }

        return $this->formatter->formatOrder($contract, $this->permalinkFor($outcome->orderId));
    }
}
```

Wire the four headless collaborators (all public services of payment-base) into your service:

```yaml
  MyVendor\MyProvider\Mcp\Service\MyProviderCheckoutService:
    arguments:
      $contractService: '@OxidEsales\PaymentBase\Service\ContractServiceInterface'
      $contractRepository: '@OxidEsales\PaymentBase\Repository\ContractRepositoryInterface'
      $eventDispatcher: '@OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface'
      $formatter: '@OxidEsales\PaymentBase\Mcp\Acp\AcpResponseFormatterInterface'
      $contractOpening: '@OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface'
      $userBaskets: '@OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface'
      $buyers: '@OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface'
      $contractCommit: '@OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface'
```

The base class (`AbstractAcpCheckoutService`) already handles:
- `createCheckout()` — see above (override only when needed)
- `getCheckout()` — loads contract by ID, returns formatted response
- `updateCheckout()` — updates contract metadata with `acp_` prefix
- `cancelCheckout()` — validates contract state, calls `cancel()` on contract
- `completeCheckout()` — validates contract, checks for token, stores agent metadata, delegates to your `completePayment()`
- `commitPaid()` — commits through `ContractCommitServiceInterface` with `source: acp`

### 2. Create the Product Service

```php
namespace MyVendor\MyProvider\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;

class MyProviderProductService implements AcpProductServiceInterface
{
    public function listProducts(array $filters = []): array
    {
        $search = $filters['search'] ?? '';
        $limit = $filters['limit'] ?? 20;
        $offset = $filters['offset'] ?? 0;

        // Query shop database for products
        return [
            'products' => $products,
            'total' => $totalCount,
        ];
    }

    public function getProduct(string $productId): ?array
    {
        // Load single product by ID
        return $product ?: null;
    }
}
```

### 3. Create the MCP Controller

Thin controller that authenticates and dispatches `McpRequestReceivedEvent`:

```php
namespace MyVendor\MyProvider\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Event\McpRequestReceivedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\EventContext;

class McpController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function handleRequest(): void
    {
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            $this->jsonResponse(401, ['error' => $authResult->getMessage()]);
            return;
        }

        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            $this->jsonResponse(400, ['error' => 'Empty request body']);
            return;
        }

        $context = new EventContext();
        $context->set('rawJsonRpc', $rawBody);
        $context->set('agentContext', $authResult->getAgentContext());

        $event = new McpRequestReceivedEvent($context);
        $this->eventDispatcher->dispatch($event);

        $response = $context->get('mcpResponse');
        $this->jsonResponse(200, $response ?? ['error' => 'No handler response']);
    }
}
```

### 4. Create the UCP Checkout Controller

Handles REST routing and dispatches a provider-specific UCP event:

```php
namespace MyVendor\MyProvider\Mcp\Controller;

class UcpCheckoutController
{
    public function handleRequest(): void
    {
        // 1. Authenticate via McpAuthGuard
        // 2. Validate headers via UcpRequestValidator (Request-Id required)
        // 3. Parse HTTP method and path segments
        // 4. Dispatch UcpCheckoutRequestEvent with context:
        //    - httpMethod, pathSegments, requestBody, agentContext
        // 5. Read httpStatusCode + responseData from context
        // 6. Output JSON response
    }
}
```

### 5. Create the UCP Checkout Handler

Routes REST requests to the checkout service:

```php
namespace MyVendor\MyProvider\Mcp\Handler;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\EventSystem\HandlerInterface;

class UcpCheckoutRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public function getHandledEventClass(): string
    {
        return MyUcpCheckoutRequestEvent::class;
    }

    public function handle(object $event): void
    {
        $context = $event->getContext();
        $method = $context->get('httpMethod');
        $segments = $context->get('pathSegments');
        $body = $context->get('requestBody') ?? [];
        $agentContext = $context->get('agentContext');

        // Route by method + path:
        // POST /checkout            → createCheckout (201)
        // GET  /checkout/{id}       → getCheckout (200)
        // PUT  /checkout/{id}       → updateCheckout (200)
        // POST /checkout/{id}/complete → completeCheckout (200)
        // POST /checkout/{id}/cancel   → cancelCheckout (200)
    }
}
```

### 6. Create the UCP Profile Controller

```php
namespace MyVendor\MyProvider\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface;

class UcpProfileController
{
    public function __construct(private readonly UcpProfileInterface $profile) {}

    public function handleRequest(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($this->profile->toArray());
    }
}
```

### 7. Register Controllers

In your module's `metadata.php`:

```php
'controllers' => [
    'myprovider_mcp'         => \MyVendor\MyProvider\Mcp\Controller\McpController::class,
    'myprovider_ucp'         => \MyVendor\MyProvider\Mcp\Controller\UcpCheckoutController::class,
    'myprovider_ucp_profile' => \MyVendor\MyProvider\Mcp\Controller\UcpProfileController::class,
],
```

Add a module setting for the agent API key:

```php
'settings' => [
    ['name' => 'sMyProviderAgentApiKey', 'type' => 'str', 'value' => ''],
],
```

### 8. Wire Services (services.yaml)

```yaml
# MCP Server (provided by payment-component)
OxidEsales\PaymentComponent\Mcp\McpServerInterface:
  class: OxidEsales\PaymentComponent\Mcp\McpServer
  arguments:
    $taggedTools: !tagged_iterator payment.mcp_tool
    $serverName: 'oxid-myprovider-acp'
    $serverVersion: '1.0.0'
  public: true

# Auth guard (provided by payment-component, configured by you)
OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface:
  class: OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuard
  arguments:
    $expectedToken: '%myprovider.agent_api_key%'

# MCP event handler (provided by payment-component)
OxidEsales\PaymentComponent\Mcp\Handler\McpRequestHandler:
  tags:
    - { name: payment.event_handler, priority: 100 }

# Tag all 6 ACP tools
OxidEsales\PaymentComponent\Mcp\Acp\Tool\CreateCheckoutTool:
  tags: [{ name: payment.mcp_tool }]
OxidEsales\PaymentComponent\Mcp\Acp\Tool\GetCheckoutTool:
  tags: [{ name: payment.mcp_tool }]
OxidEsales\PaymentComponent\Mcp\Acp\Tool\UpdateCheckoutTool:
  tags: [{ name: payment.mcp_tool }]
OxidEsales\PaymentComponent\Mcp\Acp\Tool\CompleteCheckoutTool:
  tags: [{ name: payment.mcp_tool }]
OxidEsales\PaymentComponent\Mcp\Acp\Tool\CancelCheckoutTool:
  tags: [{ name: payment.mcp_tool }]
OxidEsales\PaymentComponent\Mcp\Acp\Tool\ListProductsTool:
  tags: [{ name: payment.mcp_tool }]

# Bind ACP interfaces to your implementations
OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface:
  class: MyVendor\MyProvider\Mcp\Service\MyProviderCheckoutService

OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface:
  class: MyVendor\MyProvider\Mcp\Service\MyProviderProductService

# ACP response formatter (provided by payment-component)
OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatterInterface:
  class: OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatter
  arguments:
    $paymentProviders:
      - { provider: 'myprovider', supported_payment_methods: ['card'] }

# UCP profile (provided by payment-component, configured by you)
OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface:
  class: OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfile
  arguments:
    $restEndpoint: '%myprovider.ucp.rest_endpoint%'
    $capabilities:
      - !service
        class: OxidEsales\PaymentComponent\Mcp\Ucp\UcpCapability
        arguments:
          $name: 'dev.ucp.shopping.checkout'
          $version: '2026-01-11'
    $paymentHandlers:
      - { id: 'myprovider', spec: 'https://myprovider.example.com/ucp-handler', version: '2026-01-11' }

# UCP utilities (provided by payment-component)
OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface:
  class: OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatter
OxidEsales\PaymentComponent\Mcp\Ucp\UcpCapabilityNegotiationService: ~
OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator: ~

# Your UCP handler
MyVendor\MyProvider\Mcp\Handler\UcpCheckoutRequestHandler:
  tags:
    - { name: payment.event_handler, priority: 100 }

# HTTP client (your implementation)
OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface:
  class: MyVendor\MyProvider\Mcp\Http\MyCurlHttpClient
```

## Checklist

Before shipping your provider module's MCP/ACP/UCP support:

- [ ] `AbstractAcpCheckoutService` extended with `createCheckout()` and `completePayment()`
- [ ] `AcpProductServiceInterface` implemented
- [ ] 3 controllers registered in `metadata.php`
- [ ] Agent API key setting added to module settings
- [ ] All 6 tools tagged as `payment.mcp_tool` in `services.yaml`
- [ ] UCP profile configured with correct capabilities and payment handler
- [ ] UCP checkout handler registered as `payment.event_handler`
- [ ] Unit tests for checkout service, handler, and controller
- [ ] Manual curl test: `initialize` → `tools/list` → `create_checkout` → `complete_checkout`
- [ ] MCP Inspector verification of all tool schemas
