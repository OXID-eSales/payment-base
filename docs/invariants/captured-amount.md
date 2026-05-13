# Invariant — `PaymentContract::OXCAPTUREDAMOUNT` is the source of truth for "money taken"

**Audience:** anyone writing a webhook handler in a payment provider module that targets `payment-base`.

## The rule

`oe_payments_contract.OXCAPTUREDAMOUNT` (accessed via `PaymentContract::getCapturedAmount()` / `setCapturedAmount()`) is the authoritative record of "money has been taken from the customer for this contract." It **MUST** be set to a positive value whenever a successful capture event reaches the shop, regardless of:

- Which payment provider (Stripe, PayPal, Klarna, …) processed the capture.
- Whether the capture was a separate event after a prior authorization (manual capture mode — Stripe's `charge.captured`, PayPal's `authorization captured`, …) or an authorize-and-capture-in-one-step event (automatic capture mode — Stripe's `payment_intent.succeeded`, PayPal's capture-without-auth, …).

## Why this rule exists

Downstream consumers rely on `OXCAPTUREDAMOUNT` as the single source of truth:

- **opalreturns refund broker** (`PaymentBaseRefundBrokerListener`) — uses `getCapturedAmount() > 0` as the discriminator between "refund this" and "cancel the open authorization". An auto-captured contract with `OXCAPTUREDAMOUNT = NULL` cannot be refunded — the broker silently skips it.
- **Admin order detail views** — display the captured amount on the order/payment tab.
- **Reporting / reconciliation** — uses the field for accounting summaries.

If a provider's webhook handler fulfils a contract without writing this field, the contract row is left in a state that is **valid** (the FSM sees it as `committed`/`fulfilled`) but **silently broken** for refunds — the failure surfaces only when an admin tries to refund and nothing happens. That class of bug is exactly what this invariant is meant to prevent.

## What this means for new provider modules

When you add a webhook handler that fulfils a contract:

1. Extract the captured amount from the webhook payload (in currency units — convert from cents if your PSP sends minor units).
2. Call `PaymentContract::setCapturedAmount($amount)` and `setCapturedAt(new \DateTimeImmutable())` whenever the amount is `> 0`.
3. Persist via `ContractRepositoryInterface::save($contract)`.

If your provider has two webhook paths (separate authorization + capture vs auth-and-capture-in-one), **both** paths must perform this write. They are symmetric; a missing write on one path is the bug class this invariant guards against.

## Reference implementation

`extensions/stripe/src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php`:

- `handleChargeCaptured()` — manual capture path, was already correct.
- `handlePaymentSucceeded()` — auto-capture path, was the bug fixed in STRP-AUTOCAP-REFUND (2026-05-13). Now records the amount symmetrically with `handleChargeCaptured()`.

The pattern (read amount → `recordCapturedAmount()` → save) is the template for any future provider's webhook handler.

## Diagnostic

A `committed` / `fulfilled` contract row with `OXCAPTUREDAMOUNT IS NULL` is the smoking gun for this bug class. Sample query:

```sql
SELECT OXID, OXSTATE, OXCAPTUREDAMOUNT, OXPROVIDER, OXCREATED
FROM oe_payments_contract
WHERE OXSTATE IN ('committed', 'fulfilled')
  AND OXCAPTUREDAMOUNT IS NULL
ORDER BY OXCREATED DESC
LIMIT 50;
```

A non-empty result set means at least one provider's webhook handler is violating this invariant. Fix the handler; backfill historical rows from `oe_payments_transaction` capture audit rows (see `payment-base:backfill-captured-amount` console command).
