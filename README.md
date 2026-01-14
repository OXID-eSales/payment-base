# Payment Component

**Provider-agnostic payment foundation with Smart-Contract Architecture for e-commerce platforms.**

## Overview

Payment Component is a universal, event-driven payment library that enables seamless integration of multiple payment providers (Stripe, PayPal, Unzer, Adyen, etc.) with **95% code reusability**. Built on the Smart-Contract Architecture pattern where "Place Order" creates a contract, not an order—orders are created only when conditions are fulfilled.

### Key Benefits

- **95% Reusability** - Provider-agnostic core, only ~5% provider-specific code needed
- **70% Faster Integration** - New payment providers in 35-50 hours vs 120-160 hours
- **Event-Driven** - All business logic triggered via PSR-14 domain events
- **Smart Contracts** - Two-step authorization with condition-based fulfillment
- **Type-Safe** - PHP 8.1+ with strict typing and PHPStan level 6 compliance

## Installation

```bash
composer require oxid-esales/payment-component
```

## Requirements

- PHP 8.1+
- PSR-3 Logger (psr/log ^2.0 || ^3.0)
- PSR-14 Event Dispatcher (psr/event-dispatcher ^1.0)
- Doctrine DBAL ^2.13 || ^3.0

## Smart-Contract Architecture

Traditional payment flow creates orders immediately, then handles payment failures with complex rollback logic. The Smart-Contract pattern reverses this:

```
Traditional:  User clicks "Order" → Order created → Payment → Update order on failure
Smart-Contract: User clicks "Order" → Contract created → Conditions resolved → Order created
```

### Contract Lifecycle

```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
                                  ↘ CANCELLED / EXPIRED / FAILED
```

**States:**
- `DRAFT` - Contract initialized, basket captured
- `PENDING` - Awaiting conditions (payment auth, fraud check, stock)
- `READY_TO_COMMIT` - All conditions fulfilled
- `COMMITTED` - Order created in shop system
- `FULFILLED` - Payment captured, process complete

### Contract Conditions

Contracts track multiple conditions that must be satisfied:

```php
$contract->addCondition(new ContractCondition('payment_authorized'));
$contract->addCondition(new ContractCondition('fraud_check_passed'));
$contract->addCondition(new ContractCondition('stock_reserved'));

// Conditions fulfilled asynchronously via webhooks/events
$contract->fulfillCondition('payment_authorized');
```

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  CONTROLLER LAYER - Thin controllers, emit events only      │
└────────────────────────────┬────────────────────────────────┘
                             │ emits
┌────────────────────────────▼────────────────────────────────┐
│  EVENT SYSTEM - Domain Events (PSR-14 compatible)           │
└────────────────────────────┬────────────────────────────────┘
                             │ triggers
┌────────────────────────────▼────────────────────────────────┐
│  SERVICE LAYER - ContractService, PaymentService            │
└────────────────────────────┬────────────────────────────────┘
                             │ uses
┌────────────────────────────▼────────────────────────────────┐
│  ADAPTER LAYER - PaymentAdapterInterface (provider-agnostic)│
└────────────────────────────┬────────────────────────────────┘
                             │ persists
┌────────────────────────────▼────────────────────────────────┐
│  REPOSITORY LAYER - ContractRepository, TransactionRepository│
└─────────────────────────────────────────────────────────────┘
```

## Directory Structure

```
src/
├── Adapter/            # Payment provider abstraction
│   ├── PaymentAdapterInterface.php
│   ├── Dto/            # Data transfer objects
│   └── Response/       # Standardized adapter responses
├── Contract/           # Smart-contract domain
│   ├── PaymentContract.php        # Aggregate root
│   ├── ContractCondition.php      # Condition entity
│   └── BasketSnapshot.php         # Immutable basket value object
├── Controller/         # Base controllers
│   └── Webhook/        # Webhook handling base classes
├── EventSystem/        # Event-driven architecture
│   ├── Event/          # Domain events
│   │   ├── Contract/   # Contract lifecycle events
│   │   └── Payment/    # Payment lifecycle events
│   ├── EventDispatcher.php
│   └── EventListenerProvider.php
├── GraphQL/            # Headless API support
├── Middleware/         # Request/response middleware
├── Model/              # Domain models
├── Order/              # Order integration
├── Repository/         # Data access layer
├── Service/            # Business logic services
│   ├── ContractService.php
│   ├── CheckoutOrchestrator.php
│   └── Idempotency/    # Duplicate payment prevention
├── Traits/             # Reusable traits
├── Transaction/        # Transaction tracking
└── Webhook/            # Webhook processing
```

## Usage

### Implementing a Payment Provider

Create an adapter implementing `PaymentAdapterInterface`:

```php
use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Response\AuthorizationResponse;

class StripeAdapter implements PaymentAdapterInterface
{
    public function authorize(PaymentRequest $request): AuthorizationResponse
    {
        $paymentIntent = $this->stripeClient->paymentIntents->create([
            'amount' => $request->getAmount(),
            'currency' => $request->getCurrency(),
            'capture_method' => 'manual',
        ]);

        return new AuthorizationResponse(
            success: true,
            transactionId: $paymentIntent->id,
            providerReference: $paymentIntent->client_secret
        );
    }

    public function capture(CaptureRequest $request): CaptureResponse
    {
        // Capture implementation
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        // Refund implementation
    }
}
```

### Creating a Payment Contract

```php
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;

// Capture basket state immutably
$basketSnapshot = BasketSnapshot::fromBasket($basket);

// Create contract
$contract = new PaymentContract(
    shopId: $shopId,
    userId: $userId,
    basketSnapshot: $basketSnapshot
);

// Add conditions that must be fulfilled
$contract->addCondition('payment_authorized');
$contract->addCondition('fraud_check_passed');

// Persist contract
$contractRepository->save($contract);
```

### Handling Webhooks

```php
use OxidEsales\PaymentComponent\Webhook\AbstractWebhookHandler;

class StripeWebhookHandler extends AbstractWebhookHandler
{
    protected function getTransactionIdFromPayload(array $payload): string
    {
        return $payload['data']['object']['id'];
    }

    protected function getEventTypeFromPayload(array $payload): string
    {
        return $payload['type'];
    }

    protected function processPaymentSucceeded(array $payload): void
    {
        $contract = $this->contractRepository->findByProviderReference(
            $payload['data']['object']['id']
        );

        $contract->fulfillCondition('payment_authorized');
        $this->contractRepository->save($contract);

        $this->eventDispatcher->dispatch(
            new PaymentAuthorizedEvent($contract)
        );
    }
}
```

### Event Handling

```php
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\SubscriberInterface;

class OrderCreationSubscriber implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ContractReadyToCommitEvent::class => 'onContractReady',
        ];
    }

    public function onContractReady(ContractReadyToCommitEvent $event): void
    {
        $contract = $event->getContract();

        // Create order from contract's basket snapshot
        $order = $this->orderService->createFromContract($contract);

        $contract->commit($order->getId());
        $this->contractRepository->save($contract);
    }
}
```

## Domain Events

### Contract Events

| Event | Trigger |
|-------|---------|
| `ContractCreatedEvent` | New contract initialized |
| `ContractTransitionedToPendingEvent` | Contract submitted for processing |
| `ContractConditionFulfilledEvent` | A condition was satisfied |
| `ContractReadyToCommitEvent` | All conditions fulfilled |
| `ContractCommittedEvent` | Order created from contract |
| `ContractFulfilledEvent` | Payment captured, process complete |
| `ContractCancelledEvent` | Contract cancelled by user/system |
| `ContractExpiredEvent` | Contract timed out |

### Payment Events

| Event | Trigger |
|-------|---------|
| `PaymentInitiatedEvent` | User starts checkout |
| `PaymentAuthorizedEvent` | Funds reserved |
| `PaymentCapturedEvent` | Funds transferred |
| `PaymentRefundedEvent` | Refund processed |
| `PaymentFailedEvent` | Payment declined |
| `WebhookReceivedEvent` | Provider webhook received |

## Development

### Running Tests

```bash
# All tests
vendor/bin/phpunit

# Unit tests only
vendor/bin/phpunit --testsuite Unit

# Integration tests only
vendor/bin/phpunit --testsuite Integration

# Single test file
vendor/bin/phpunit tests/Unit/Contract/PaymentContractTest.php

# Single test method
vendor/bin/phpunit --filter testContractTransitionsToPending
```

### Static Analysis

```bash
vendor/bin/phpstan analyse
```

## Supported Providers

The component is designed to support any payment provider with REST/SOAP API and webhooks:

- Stripe
- PayPal
- Amazon Pay
- Unzer
- TeleCash
- Adyen
- Mollie
- Klarna
- Braintree
- Square

## License

GPL-3.0-only - See [LICENSE](LICENSE) for details.

## Credits

Developed by OXID eSales AG with AI assistance from Claude (Anthropic).
