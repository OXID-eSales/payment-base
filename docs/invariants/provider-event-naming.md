# Invariant — provider-specific event class naming convention

**Audience:** anyone adding a payment provider module on top of `payment-base`.

## The convention

`payment-base` dispatches generic provider-agnostic request events through `EventBroker`:

- `RefundRequestedEvent`
- `CaptureRequestedEvent`
- `CancelAuthorizationRequestedEvent`

The broker resolves the right provider-specific event class by reading `$contract->getProvider()` and either:

1. **(Explicit)** Invoking the first `ProviderEventTranslatorInterface` whose `supports($providerId)` returns `true`. The translator maps the generic event onto the provider's concrete event class.
2. **(Convention-based fallback)** If no translator matches, the broker constructs the provider-specific class FQCN by the following pattern, then `class_exists()`-checks it, instantiates it from the same `EventContext` + amount + reason, and dispatches it:

```
OxidEsales\Payments\{Canonical}\EventSystem\Event\{Canonical}{BaseName}
```

Where:

- `{Canonical}` = `ucfirst(strtolower($providerId))`, OR a registered canonical override (for providers whose PascalCase form differs from `ucfirst()` — e.g. `paypal` → `PayPal`).
- `{BaseName}` ∈ { `RefundRequestEvent`, `CaptureRequestEvent`, `CancelAuthorizationRequestEvent` }.

## Worked examples

```
provider id  + base name                        → FQCN
'stripe'     + 'RefundRequestEvent'             → OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent
'stripe'     + 'CaptureRequestEvent'            → OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent
'stripe'     + 'CancelAuthorizationRequestEvent'→ OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent
'paypal'     + 'RefundRequestEvent'             → OxidEsales\Payments\PayPal\EventSystem\Event\PayPalRefundRequestEvent     (with canonical name registered)
'klarna'     + 'RefundRequestEvent'             → OxidEsales\Payments\Klarna\EventSystem\Event\KlarnaRefundRequestEvent     (zero-config, ucfirst matches)
```

## What a new provider must do

To slot a new payment provider into the refund / capture / cancel pipeline:

1. **Register the provider id** on `oe_payments_contract.OXPROVIDER` for orders processed by this provider (`PaymentContract::setProvider(...)`).
2. **Implement the three event classes** following the convention above. Each class implements `EventInterface` and accepts `(EventContext, ?float $amount, ?string $reason)` in its constructor — same shape as the generic `AbstractProviderRequestEvent`.
3. **Subscribe handlers** to those concrete event classes via the standard `payment.event_handler` tag in the module's `services.yaml`. The handlers do the actual PSP REST/SDK call.
4. **(If needed) Register the canonical name** — only if your provider id's PascalCase form is not `ucfirst($providerId)`. Example for PayPal:

```yaml
# extensions/<your-module>/services.yaml
services:
  app.paymentbase.canonical_name_registration:
    class: OxidEsales\PaymentBase\EventSystem\Broker\ConventionProviderEventResolver
    calls:
      - [registerCanonicalName, ['paypal', 'PayPal']]
```

5. **(Override path, rarely needed)** If your event class layout cannot follow the convention (e.g. you ship classes in a non-conforming namespace), implement `ProviderEventTranslatorInterface` and register it as a service. The translator takes precedence over the convention.

## What `payment-base` and `opalreturns` must NOT do

- **Never** name a specific provider in `payment-base/src/` or `opalreturns/src/`. No `use OxidEsales\Payments\Stripe\…;`, no `if ($provider === 'stripe')`.
- **Never** broadcast a generic request event to all registered translators. Routing is by provider, period.

The opalreturns architecture test (`Opal\OpalReturns\Tests\Unit\Architecture\NoConcretePspReferencesTest`) enforces the first rule.

## Reference

- `extensions/payment-base/src/EventSystem/Broker/EventBroker.php` — routing logic.
- `extensions/payment-base/src/EventSystem/Broker/ConventionProviderEventResolver.php` — FQCN resolver.
- `extensions/payment-base/src/EventSystem/Broker/ProviderEventTranslatorInterface.php` — explicit-override path.
- `extensions/stripe/src/Stripe/EventSystem/Translator/StripeEventTranslator.php` — Stripe's explicit translator (kept for backwards compatibility; the convention path would also work).
- `extensions/payment-base/tests/Unit/EventSystem/Broker/ConventionProviderEventResolverTest.php` — convention resolver tests.
