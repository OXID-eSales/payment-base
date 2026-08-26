# 2026-08-26 — day summary (payment-base)

**Branch:** `b-7.4.x` · **CI:** green on OXID 7.4 and 7.5

---

## 1. What shipped

**Sprint 06 — single active payment method.** A shop that offers exactly one
usable payment method no longer shows a payment selection: the method is
assigned while the payment step renders, that step's selection block is replaced
by the bare form it lives in, and the order page drops its payment block.

The rule reads OXID's own filtered payment list, so plain core methods
(`oxidinvoice`, `oxidcashondel`) count exactly like a PSP's and it works with no
PSP module installed. Confirmed twice over: the sprint was verified against
Stripe, and later — when the shop happened to have only Mollie active — it
auto-assigned Mollie with no code change.

Kill switch `blPaymentBaseAutoAssignSinglePayment`, default on; it cannot fire
unless the list has exactly one entry.

→ [report 01](01-sprint-06-implementation-report.md), [report 03](03-delivery.md)

## 2. The correction worth remembering

The first implementation **skipped the payment step** with a redirect. That was
wrong for two reasons found only on the live shop, and both are the kind that no
unit test would have caught:

1. The step is "Zahlung **& Versand**" — the delivery-set selector lives on the
   same page, so skipping took the shipping choice away with it.
2. The step's "next" button sits *outside* the payment form and submits it by id
   (`document.querySelector('#payment').requestSubmit()`), so removing the form
   left the customer with no way forward.

What ships is block replacement, not step skipping. → report 01 §0

## 3. What the sprint uncovered elsewhere

Installing one new module setting recompiled the DI container, and that exposed
three latent defects in `stripe` — one of which had checkout dead, one of which
refused a payment *after* the customer was charged. None were caused by this
sprint. They are written up in the Stripe dev log for today:

`extensions/stripe/docs/oe_payments_docs/daniil_dev_log/20260826/reports/03-day-summary.md`

Two lessons that belong to payment-base as a platform:

- **A consumer that copies core markup must opt in.** Stripe's order template
  replaces core's whole `shippingAndPayment` block, so a payment-base override of
  a block nested inside it is unreachable. Check with
  `grep -l 'id="orderPayment"' extensions/*/views` before relying on one; only
  stripe does this today.
- **New non-service classes under a swept namespace break the container.** Adding
  a value object under `src/Stripe/Service/*` broke compilation on every page
  until it was excluded. Same trap exists in any module with a `resource:` sweep.

→ [report 02](02-checkout-return-walkthrough.md), which also records why Mollie's
return path never hit any of it.

## 4. Commits

| Commit | Subject |
|---|---|
| `65a0dc9` | Sprint 06 S1-S3: decide when a single payment method needs no selection |
| `a945fda` | Sprint 06 S4: hide the payment blocks when there is nothing to choose |
| `e3161c2` | docs: sprint 06 plan, engineering requirements and delivery reports |
| `5acf027` | docs: sprint 06 delivery report |
| `69e3a60` | test(integration): keep the renderer-dependent template tests out of CI |
| `e86dba7` | test(integration): give the renderer-dependent tests their own config |

## 5. Verification

| Gate | Result |
|---|---|
| `phpcs` · `phpstan --level=max` · `phpmd` | clean, baselines not grown |
| Unit | **1182** tests / 2579 assertions (from 1128 / 2498) |
| Integration (the config CI runs) | **95** tests / 302 assertions |
| Integration-renderer (own config, needs a themed shop) | 7 tests / 20 assertions |
| consumers: mollie / paypal / opalreturns | 518 / 449 / 353 — all OK |
| consumer: one-page-checkout | 316 / 775 / 1 error — identical before and after |
| **CI** | **green, 7.4 and 7.5** |

The two template-render tests needed their own config file: CI's shop image has
no frontend theme and its renderer answers with the template *name* instead of
markup. A first attempt used a second suite in the same file, which changed
nothing — CI runs phpunit without naming a suite, so every suite executes. Each
test now asserts the renderer really rendered, so a themeless shop fails loudly
rather than passing by accident.

## 6. Open

- **The order-overview question.** Hiding the block removes the payment method
  from the final overview, which is awkward for DE shops. The fallback is one
  condition — override `checkout_order_payment_method_form` instead of the whole
  block, keeping a static method line and dropping only the pencil. Needs a legal
  call, not a technical one. → report 01 §6
- **Default on.** Any single-method shop that pulls this changes behaviour without
  touching a setting. Worth a line in the release notes.
