# Sprint 06 — delivery

**Date:** 2026-08-26
**Branch:** `b-7.4.x` (payment-base), plus consumer commits in `stripe`

---

## 1. What shipped

A shop that offers exactly one usable payment method no longer shows a payment
selection. The method is assigned while the payment step renders, that step's
selection block is replaced by the bare form it lives in, and the order page
drops its payment block. The rule reads OXID's own filtered payment list, so
plain core methods (`oxidinvoice`, `oxidcashondel`) count exactly like a PSP's
and it works with no PSP module installed.

Kill switch: `blPaymentBaseAutoAssignSinglePayment`, default on — it cannot fire
unless the list has exactly one entry.

Details in [report 01](01-sprint-06-implementation-report.md) (§0 records the
correction after review: block replacement, not step skipping).

## 2. Commits

**payment-base** (`b-7.4.x`)

| Commit | Subject |
|---|---|
| `65a0dc9` | Sprint 06 S1-S3: decide when a single payment method needs no selection |
| `a945fda` | Sprint 06 S4: hide the payment blocks when there is nothing to choose |
| `e3161c2` | docs: sprint 06 plan, engineering requirements and delivery reports |

**stripe** (`b-7.4.x`) — consumer side and the defects the sprint uncovered

| Commit | Subject |
|---|---|
| `9189b6f` | fix(di): make runtime-fetched services public, keep return VOs out of the sweep |
| `bfac6ca` | fix(checkout-return): stop refusing a paid return, and say why when we do |
| `cbc174c` | fix(order-page): honour the single-payment decision in Stripe's own block |
| `800dc9f` | chore(e2e): bump submodule — single-active-payment and eager-mount specs |

**e2e-tests-playwright** (submodule, `projects/Stripe`)

| Commit | Subject |
|---|---|
| `fbf595e` | test(checkout): single-active-payment walkthrough and eager-mount guard |

Nothing pushed yet. The submodule has to go first, or stripe's pointer dangles
for everyone else.

## 3. Verification at delivery

| Gate | Result |
|---|---|
| payment-base `phpcs` / `phpstan --level=max` / `phpmd` | clean, baselines not grown |
| payment-base Unit | 1182 tests / 2579 assertions (from 1128 / 2498) |
| payment-base Integration | 102 tests / 314 assertions (from 85 / 287) |
| consumers: mollie / paypal / opalreturns | 518 / 449 / 353 — all OK |
| consumer: one-page-checkout | 316 / 775 / 1 error — **identical before and after** |
| stripe `phpcs` / `phpstan --level=max` / `phpmd` | clean |
| stripe unit (per file — the suite cannot be built here) | 39 tests / 74 assertions |
| E2E, live shop | full walkthrough → `cl=thankyou`, contract `committed`, order **247**, `OXPAID` set |

## 4. What a reviewer should know

- **The sprint's own scope is small; the collateral was not.** Recompiling the DI
  container to register one setting exposed two long-latent private-service
  defects in stripe, one of which had checkout dead. That is a property of the
  container, not of this sprint — see the stripe dev log for today.
- **A consumer that copies core markup has to opt in.** Stripe's order template
  replaces the whole `shippingAndPayment` block, so a payment-base override of a
  block nested inside it is unreachable. Only stripe does this
  (`grep -l 'id="orderPayment"' extensions/*/views`); mollie and paypal do not.
- **Still open, and the reason to look at the stripe report:** one order-page load
  in iframe mode creates several Stripe sessions, contracts and early orders. The
  return can no longer be refused because of it, but the duplication is real and
  wants its own sprint.
- **Still undecided (§6 of report 01):** whether the order overview should keep a
  static, non-clickable payment line instead of dropping the block entirely. One
  Twig condition either way; a DE legal review should settle it.
