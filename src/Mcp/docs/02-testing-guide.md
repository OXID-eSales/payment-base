# MCP/ACP/UCP Testing Guide

## Running Tests

All tests run inside Docker from the project root.

### payment-component Unit Tests

```bash
docker compose exec php php vendor/bin/phpunit \
  -c extensions/payment-component/tests/phpunit.xml \
  --testsuite Unit \
  --filter Mcp
```

Single test file:
```bash
docker compose exec php php vendor/bin/phpunit \
  -c extensions/payment-component/tests/phpunit.xml \
  extensions/payment-component/tests/Unit/Mcp/McpServerTest.php
```

Single test method:
```bash
docker compose exec php php vendor/bin/phpunit \
  -c extensions/payment-component/tests/phpunit.xml \
  --filter testInitializeReturnsProtocolVersion \
  extensions/payment-component/tests/Unit/Mcp/McpServerTest.php
```

## Test Coverage Map (11 files)

| Test File | Covers |
|-----------|--------|
| `Mcp/McpServerTest.php` | JSON-RPC routing: `initialize`, `tools/list`, `tools/call`, error handling |
| `Mcp/AgentContextTest.php` | Agent context value object (agent ID, token) |
| `Mcp/Auth/AuthResultTest.php` | `AuthResult::success()` / `AuthResult::failed()` factories |
| `Mcp/Auth/McpAuthGuardTest.php` | Bearer token extraction, `hash_equals` validation, agent ID derivation |
| `Mcp/Acp/AcpResponseFormatterTest.php` | Contract-to-ACP status mapping, line item formatting, minor unit conversion |
| `Mcp/Acp/AbstractAcpCheckoutServiceTest.php` | `getCheckout`, `updateCheckout`, `cancelCheckout`, `completeCheckout` base logic |
| `Mcp/Ucp/UcpProfileTest.php` | Profile `toArray()` shape, version `2026-01-11`, capabilities |
| `Mcp/Ucp/UcpRequestValidatorTest.php` | `Request-Id` header validation, `UCP-Agent` profile URL extraction |
| `Mcp/Ucp/UcpCapabilityTest.php` | Capability value object with optional spec and extensions |
| `Mcp/Ucp/UcpCapabilityNegotiationServiceTest.php` | Capability negotiation: intersection of business and agent capabilities |
| `Mcp/Ucp/UcpResponseFormatterTest.php` | Contract-to-UCP status mapping, line item formatting |

## Testing Patterns

### Mock Interfaces, Not Classes

Always mock interfaces to catch method signature mismatches:

```php
$checkoutService = $this->createMock(AcpCheckoutServiceInterface::class);
$checkoutService->method('createCheckout')
    ->willReturn(['id' => 'contract_123', 'status' => 'not_ready_for_payment']);
```

### AAA Structure (Arrange-Act-Assert)

```php
public function testGetCheckoutReturnsFormattedContract(): void
{
    // Arrange
    $contract = $this->createMock(PaymentContractInterface::class);
    $contract->method('getId')->willReturn('c_123');
    $this->contractRepository->method('findById')->willReturn($contract);
    $this->formatter->method('formatCheckout')->willReturn(['id' => 'c_123']);

    // Act
    $result = $this->service->getCheckout('c_123');

    // Assert
    $this->assertSame('c_123', $result['id']);
}
```

### Intersection Types for Event Mocks

When mocking events that carry context:

```php
$event = $this->createMock(McpRequestReceivedEvent::class);
$context = new EventContext();
$context->set('rawJsonRpc', '{"jsonrpc":"2.0","id":1,"method":"initialize"}');
$context->set('agentContext', new AgentContext('agent_abc', 'token'));
$event->method('getContext')->willReturn($context);
```

### Factory Methods for Common Test Data

```php
private function createAgentContext(string $agentId = 'agent_test'): AgentContext
{
    return new AgentContext($agentId, 'test_token_' . $agentId);
}

private function createCheckoutArguments(array $overrides = []): array
{
    return array_merge([
        'items' => [['id' => 'product_1', 'quantity' => 1]],
        'buyer' => ['email' => 'test@example.com'],
        'currency' => 'EUR',
    ], $overrides);
}
```

### Testing AbstractAcpCheckoutService Subclasses

Create a concrete test double to test the abstract base class:

```php
class TestableCheckoutService extends AbstractAcpCheckoutService
{
    public array $lastPaymentData = [];

    public function createCheckout(array $arguments, AgentContextInterface $agentContext): array
    {
        return ['id' => 'test_contract'];
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array {
        $this->lastPaymentData = $paymentData;
        return $this->formatter->formatOrder($contract, 'https://example.com/order/1');
    }
}
```

## MCP Inspector

The MCP Inspector provides a visual debugging UI for JSON-RPC interactions. It works with any provider module's MCP endpoint.

### Setup

```bash
npx @modelcontextprotocol/inspector
```

This opens a web UI (usually at `http://localhost:5173`).

### Configuration

In the Inspector UI:
1. Set **Transport** to `SSE` or `Streamable HTTP`
2. Set **URL** to your provider module's MCP endpoint
3. Add header: `Authorization: Bearer <your-api-key>`

### What You Can Do

- Send `initialize` and see protocol version negotiation
- Browse all 6 tools with their full input schemas
- Call tools with custom arguments and see formatted responses
- Inspect raw JSON-RPC request/response pairs
- Debug error responses and error codes

### Common Debugging Steps

1. **Verify connection**: Send `initialize` — should return `protocolVersion: 2025-06-18`
2. **Check tools**: Send `tools/list` — should show all 6 tools
3. **Test create**: Call `create_checkout` with valid items — should return checkout with status `not_ready_for_payment`
4. **Test errors**: Call `get_checkout` with invalid ID — should return not-found error
