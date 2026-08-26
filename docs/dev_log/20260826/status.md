# 2026-08-26 — payment-base

## Sprints

- **Sprint 06** — [Single active payment method: auto-assign and hide the payment blocks](sprints/sprint-06-single-active-payment-auto-assign.md) — *implemented, verified live*
  Binding requirements: [`sprints/_engeneering_requirements.md`](sprints/_engeneering_requirements.md)
  Report: [reports/01-sprint-06-implementation-report.md](reports/01-sprint-06-implementation-report.md)

## Follow-up (not sprint 06)

- [Checkout return walkthrough](reports/02-checkout-return-walkthrough.md) — the
  "Payment verification failed" report. Return path now logs **which** of six checks
  refused (TDD, 17 unit tests); two private-service defects fixed; one autowire-sweep
  regression from this work fixed. **Root cause found and fixed**: the return compared
  the contract id against a session pointer that names only the last of several contracts
  a checkout creates, so paying an earlier sheet was refused after the customer was
  charged. Replaced by an ownership check (the id is already HMAC-authenticated).
  **Confirmed end to end**: full walkthrough through the Stripe hosted page →
  `cl=thankyou`, contract `committed`, order **247** `OXPAID` set, no rejection logged
  (report §5). `blPaymentBaseUseIframe` was flipped off for that one run and restored.
  Still open: the duplicate checkout sessions themselves (two producers —
  `StripeOrderController::createCheckoutSession` and `StripePaymentHandler`).

## State

- payment-base gates green: phpcs, phpstan **level max**, phpmd, Unit 1182, Integration 102.
- Verified on `daniil.oxiddev.de`: payment step shows no selection (form + shipping intact),
  order page shows no payment block. Mechanism is **block replacement, not step skipping** —
  the step also carries shipping and its "next" button submits the payment form by id.
- `extensions/stripe`'s order template needed a one-condition opt-in: it replaces the whole
  `shippingAndPayment` section with its own copy for Stripe payments.
- Consumers unaffected: mollie 518, paypal 449, opalreturns 353 — all OK; OPC identical before/after.
- Open: pressing through to a placed order in a browser; decision on whether the order
  overview keeps a static payment line (see report §6).
- **Fixed on the way** (latent, surfaced by this sprint's container recompile):
  stripe's `RetryCleanupService` and `StripeOrderApiService` were private yet fetched
  by id — the first killed checkout with "Payment processing failed", the second
  silently emptied the admin order tab's Stripe history. Both now `public: true`.
- Found pre-existing, still open: the stripe unit suite cannot be built in this shop
  and one OPC controller test errors — both OXID class-chain recursion under PHPUnit.
  See report §7.
