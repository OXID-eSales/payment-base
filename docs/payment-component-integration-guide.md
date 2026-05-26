# Payment Base Integration Guide

## For Shipping, CRM, ERP and Third-Party Module Developers

**Package:** `oxid-esales/payment-base`
**Architecture:** Event-Driven (PSR-14 Compatible)

---

## Table of Contents

1. [Overview](#overview)
2. [Event-Driven Architecture](#event-driven-architecture)
3. [Available Events](#available-events)
4. [Integration Examples](#integration-examples)
5. [Best Practices](#best-practices)
6. [Database Schema](#database-schema)

---

## Overview

The `oxid-esales/payment-base` provides a **provider-agnostic event-driven architecture** that allows third-party modules to react to payment lifecycle changes. This enables:

- **Shipping modules**: Update shipment status when payment is captured
- **CRM systems**: Sync customer data when orders are created
- **ERP systems**: Export orders when payment is confirmed
- **Inventory systems**: Reserve/release stock based on payment status
- **Notification services**: Send emails/SMS on payment events

### Why Event-Driven?

Traditional approach (tightly coupled):
```
PaymentService → ShippingService → CRMService → ERPService
```

Event-driven approach (loosely coupled):
```
PaymentCapturedEvent → [ShippingHandler, CRMHandler, ERPHandler]
                       (independent, parallel execution)
```

**Benefits:**
- Modules don't depend on each other
- Add/remove integrations without code changes
- Each handler fails independently
- Easy to test in isolation

---

## Event-Driven Architecture

### How It Works

```
┌─────────────────┐     ┌────────────────────┐     ┌─────────────────┐
│  Payment Module │────▶│  EventDispatcher   │────▶│  Your Handler   │
│  (Stripe, etc.) │     │  (PSR-14)          │     │  (CRM, ERP...)  │
└─────────────────┘     └────────────────────┘     └─────────────────┘
        │                        │                         │
        │ dispatch(event)        │ notify subscribers      │ handle(event)
        ▼                        ▼                         ▼
   PaymentCapturedEvent    EventListenerProvider     Update external system
```

### Event Flow

1. **Payment module** performs an action (capture, refund, etc.)
2. **Event** is dispatched with relevant context data
3. **EventDispatcher** notifies all registered handlers
4. **Your handler** receives the event and processes it
5. Each handler operates **independently** - failures don't affect others

---

## Available Events

### Contract Lifecycle Events

These events track the payment contract through its lifecycle:

| Event | When Fired | Use Case |
|-------|------------|----------|
| `ContractCreatedEvent` | Customer initiates checkout | Reserve inventory |
| `ContractTransitionedToPendingEvent` | Payment process started | Lock basket items |
| `ContractReadyToCommitEvent` | All conditions met | Prepare order data |
| `ContractCommittedEvent` | Order created in shop | Create ERP order |
| `ContractFulfilledEvent` | Payment completed | Trigger fulfillment |
| `ContractCancelledEvent` | Payment cancelled | Release inventory |
| `ContractExpiredEvent` | Session timeout | Cleanup reservations |
| `ContractFailedEvent` | Payment failed | Log failure, notify |

### Payment Events

These events track payment-specific actions:

| Event | When Fired | Use Case |
|-------|------------|----------|
| `PaymentInitiatedEvent` | Customer starts payment | Analytics tracking |
| `PaymentAuthorizedEvent` | Payment authorized (not captured) | Fraud check |
| `PaymentCapturedEvent` | Funds captured | Ship order, sync ERP |
| `PaymentRefundedEvent` | Refund processed | Update CRM, restore stock |
| `PaymentFailedEvent` | Payment failed | Alert, retry logic |
| `OrderCreatedEvent` | Shop order created | Sync to external systems |
| `OrderCompletedEvent` | Order finalized | Send confirmation |
| `WebhookReceivedEvent` | Webhook from provider | Custom processing |

---

## Integration Examples

### Example 1: ERP Order Export

Automatically export orders to your ERP when payment is captured:

```php
<?php

namespace YourCompany\ERPConnector\EventHandler;

use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\EventHandlerInterface;
use Psr\Log\LoggerInterface;

class ERPOrderExportHandler implements EventHandlerInterface
{
    public function __construct(
        private ERPApiClient $erpClient,
        private LoggerInterface $logger
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof PaymentCapturedEvent;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentCapturedEvent) {
            return;
        }

        $context = $event->getContext();
        $orderId = $context->get('orderId');
        $amount = $context->get('amount');
        $currency = $context->get('currency');

        try {
            // Export to ERP
            $erpOrderId = $this->erpClient->createOrder([
                'external_id' => $orderId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'PAID',
                'captured_at' => date('c'),
            ]);

            $this->logger->info('Order exported to ERP', [
                'oxid_order_id' => $orderId,
                'erp_order_id' => $erpOrderId,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('ERP export failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow - let other handlers continue
        }
    }
}
```

### Example 2: CRM Customer Sync

Sync customer data to CRM when an order is created:

```php
<?php

namespace YourCompany\CRMSync\EventHandler;

use OxidEsales\PaymentBase\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\EventHandlerInterface;

class CRMCustomerSyncHandler implements EventHandlerInterface
{
    public function __construct(
        private CRMApiClient $crmClient,
        private CustomerRepository $customerRepo
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof OrderCreatedEvent;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof OrderCreatedEvent) {
            return;
        }

        $context = $event->getContext();
        $userId = $context->get('userId');
        $orderId = $context->get('orderId');

        // Get customer details
        $customer = $this->customerRepo->getById($userId);

        // Sync to CRM
        $this->crmClient->upsertCustomer([
            'email' => $customer->getEmail(),
            'name' => $customer->getFullName(),
            'last_order_id' => $orderId,
            'last_order_date' => date('c'),
            'total_orders' => $customer->getOrderCount() + 1,
        ]);
    }
}
```

### Example 3: Shipping Fulfillment Trigger

Trigger shipping fulfillment when payment is captured:

```php
<?php

namespace YourCompany\ShippingModule\EventHandler;

use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\EventHandlerInterface;

class ShippingFulfillmentHandler implements EventHandlerInterface
{
    public function __construct(
        private ShippingApiClient $shippingApi,
        private OrderRepository $orderRepo
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof PaymentCapturedEvent;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentCapturedEvent) {
            return;
        }

        $orderId = $event->getContext()->get('orderId');
        $order = $this->orderRepo->getById($orderId);

        // Create shipping label
        $shipment = $this->shippingApi->createShipment([
            'order_id' => $orderId,
            'recipient' => [
                'name' => $order->getDeliveryName(),
                'street' => $order->getDeliveryStreet(),
                'city' => $order->getDeliveryCity(),
                'zip' => $order->getDeliveryZip(),
                'country' => $order->getDeliveryCountry(),
            ],
            'items' => $order->getOrderArticles(),
            'weight' => $order->getTotalWeight(),
        ]);

        // Update order with tracking number
        $order->setTrackingNumber($shipment->getTrackingNumber());
        $order->save();
    }
}
```

### Example 4: Inventory Management

Reserve stock when contract is created, release on cancel/expire:

```php
<?php

namespace YourCompany\Inventory\EventHandler;

use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\EventHandlerInterface;

class InventoryReservationHandler implements EventHandlerInterface
{
    public function __construct(
        private InventoryService $inventory
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof ContractCreatedEvent
            || $event instanceof ContractCancelledEvent
            || $event instanceof ContractExpiredEvent;
    }

    public function handle(object $event): void
    {
        $context = $event->getContext();
        $contractId = $context->get('contractId');
        $basketItems = $context->get('basketItems');

        if ($event instanceof ContractCreatedEvent) {
            // Reserve stock
            foreach ($basketItems as $item) {
                $this->inventory->reserve(
                    $item['articleId'],
                    $item['quantity'],
                    $contractId
                );
            }
        }

        if ($event instanceof ContractCancelledEvent
            || $event instanceof ContractExpiredEvent) {
            // Release reserved stock
            $this->inventory->releaseReservation($contractId);
        }
    }
}
```

### Example 5: Webhook Notification Service

Send notifications on payment events:

```php
<?php

namespace YourCompany\Notifications\EventHandler;

use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentRefundedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentFailedEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\EventHandlerInterface;

class PaymentNotificationHandler implements EventHandlerInterface
{
    public function __construct(
        private NotificationService $notifications,
        private OrderRepository $orderRepo
    ) {}

    public function supports(object $event): bool
    {
        return $event instanceof PaymentCapturedEvent
            || $event instanceof PaymentRefundedEvent
            || $event instanceof PaymentFailedEvent;
    }

    public function handle(object $event): void
    {
        $context = $event->getContext();
        $orderId = $context->get('orderId');
        $order = $this->orderRepo->getById($orderId);
        $customerEmail = $order->getCustomerEmail();

        match (true) {
            $event instanceof PaymentCapturedEvent =>
                $this->notifications->send($customerEmail, 'payment_confirmed', [
                    'order_number' => $order->getOrderNumber(),
                    'amount' => $context->get('amount'),
                ]),

            $event instanceof PaymentRefundedEvent =>
                $this->notifications->send($customerEmail, 'refund_processed', [
                    'order_number' => $order->getOrderNumber(),
                    'refund_amount' => $context->get('refundAmount'),
                ]),

            $event instanceof PaymentFailedEvent =>
                $this->notifications->send($customerEmail, 'payment_failed', [
                    'order_number' => $order->getOrderNumber(),
                    'reason' => $context->get('failureReason'),
                ]),
        };
    }
}
```

---

## Registering Your Handler

### Step 1: Create services.yaml

In your module's `services.yaml`:

```yaml
services:
  YourCompany\ERPConnector\EventHandler\ERPOrderExportHandler:
    arguments:
      $erpClient: '@YourCompany\ERPConnector\ERPApiClient'
      $logger: '@Psr\Log\LoggerInterface'
    tags:
      - { name: 'payment.event_handler' }

  YourCompany\CRMSync\EventHandler\CRMCustomerSyncHandler:
    arguments:
      $crmClient: '@YourCompany\CRMSync\CRMApiClient'
      $customerRepo: '@YourCompany\CRMSync\CustomerRepository'
    tags:
      - { name: 'payment.event_handler' }
```

### Step 2: Register with EventListenerProvider

If using the payment-base's event system, register your handler:

```php
<?php

use OxidEsales\PaymentBase\EventSystem\EventListenerProvider;

// In your module activation or DI configuration
$provider = $container->get(EventListenerProvider::class);
$provider->addHandler($container->get(ERPOrderExportHandler::class));
```

---

## Best Practices

### 1. Handle Failures Gracefully

```php
public function handle(object $event): void
{
    try {
        // Your logic
    } catch (\Throwable $e) {
        // Log but don't rethrow
        $this->logger->error('Handler failed', [
            'handler' => static::class,
            'event' => get_class($event),
            'error' => $e->getMessage(),
        ]);
        // Consider: queue for retry, alert, etc.
    }
}
```

### 2. Use Idempotency

Webhooks may be delivered multiple times. Handle duplicates:

```php
public function handle(object $event): void
{
    $eventId = $event->getContext()->get('eventId');

    if ($this->processedEvents->has($eventId)) {
        return; // Already processed
    }

    // Process event...

    $this->processedEvents->markProcessed($eventId);
}
```

### 3. Keep Handlers Fast

For slow operations, queue them:

```php
public function handle(object $event): void
{
    // Quick: dispatch to queue
    $this->queue->push(new ERPExportJob(
        $event->getContext()->toArray()
    ));
}
```

### 4. Test Your Handlers

```php
class ERPOrderExportHandlerTest extends TestCase
{
    public function testExportsOrderOnPaymentCaptured(): void
    {
        $erpClient = $this->createMock(ERPApiClient::class);
        $erpClient->expects($this->once())
            ->method('createOrder')
            ->willReturn('ERP-12345');

        $handler = new ERPOrderExportHandler($erpClient, new NullLogger());

        $event = new PaymentCapturedEvent(new EventContext([
            'orderId' => 'OXID-123',
            'amount' => 99.99,
            'currency' => 'EUR',
        ]));

        $handler->handle($event);
    }
}
```

---

## Database Schema

The payment-base provides these tables you can query:

### oe_payments_contract

| Column | Type | Description |
|--------|------|-------------|
| OXID | VARCHAR(32) | Contract ID |
| OXSHOPID | INT | Shop ID |
| OXUSERID | VARCHAR(32) | Customer ID |
| OXORDERID | VARCHAR(32) | Order ID (after commit) |
| OXSTATUS | VARCHAR(32) | Contract status |
| OXBASKETDATA | TEXT | Basket snapshot (JSON) |
| OXCAPTUREDAMOUNT | DOUBLE | Captured amount |
| OXREFUNDEDAMOUNT | DOUBLE | Refunded amount |
| OXTIMESTAMP | TIMESTAMP | Last update |

### oe_payments_transaction

| Column | Type | Description |
|--------|------|-------------|
| OXID | VARCHAR(32) | Transaction ID |
| OXCONTRACTID | VARCHAR(32) | Contract ID (FK) |
| OXPROVIDERID | VARCHAR(255) | Provider transaction ID |
| OXTYPE | VARCHAR(32) | Transaction type |
| OXAMOUNT | DOUBLE | Amount |
| OXSTATUS | VARCHAR(32) | Transaction status |

---

## EventContext Data Reference

Common data available in event context:

| Key | Type | Available In |
|-----|------|--------------|
| `orderId` | string | All order/payment events |
| `contractId` | string | All contract events |
| `userId` | string | All events |
| `amount` | float | Payment events |
| `currency` | string | Payment events |
| `paymentIntentId` | string | Stripe events |
| `refundAmount` | float | Refund events |
| `basketItems` | array | Contract events |
| `failureReason` | string | Failed events |

---

## Support

- **Payment Base Issues**: https://github.com/OXID-eSales/payment-base/issues
- **Stripe Module Issues**: https://github.com/OXID-eSales/stripe-wallet/issues
- **OXID Documentation**: https://docs.oxid-esales.com

---

**License:** proprietary
**Copyright:** OXID eSales AG
