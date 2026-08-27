# The setting, per provider, on and off

**Date:** 2026-08-27
**Setting:** `blPaymentBaseAutoAssignSinglePayment` — *"Skip the payment step when only one payment method is available"*
**Shop:** `daniil.oxiddev.de`, one provider active at a time
**Spec:** `extensions/stripe/tests/e2e/playwright/playwright/tests/checkout/single-payment-matrix.spec.ts`

---

## 1. Why a matrix and not one walkthrough

The earlier walkthrough only ever asserted the setting's *on* state, on Stripe. Two
things were untested: that switching the setting **off** really restores the old
behaviour, and that the whole thing is genuinely provider-agnostic rather than
Stripe-shaped. Both are claims the sprint makes, so both should be checked.

The spec therefore asserts the **expected** mode rather than assuming one. Shop
state (which provider is active, what the setting is) is prepared outside and
described to it through `PROVIDER` and `EXPECT_SELECTION`.

## 2. Result — all four cells pass

| Provider | Setting | Payment radios | Order-page payment blocks | Provider payment UI | Messages shown |
|---|---|---|---|---|---|
| Stripe Wallet | **on** | 0 | 0 | 4 | none |
| Stripe Wallet | **off** | 1 | 1 | 4 | none |
| Mollie | **on** | 0 | 0 | 2 | none |
| Mollie | **off** | 1 | 1 | 2 | none |

The delivery-set selector (`select[name="sShipSet"]`) was present in all four, and
`form#payment` — the form the step's "next" button submits by id — existed in all
four. With the setting on, the assigned method travelled with the submit as a
hidden `paymentid` for the right provider.

## 3. What each cell proves

- **on** — the customer sees no payment selection and no payment block on the
  order page, while the step stays submittable and the shipping choice stays put.
- **off** — the shop behaves exactly as before the sprint: the single method is
  offered as a radio, the order page keeps its payment block. The kill switch is
  real, not decorative.
- **both providers** — the feature is driven by OXID's filtered payment list, not
  by anything Stripe-specific. Mollie needed no code of its own; its inline card
  UI renders on the order page in both modes.

Each cell also asserts the order page still offers a way to pay, and that no
Stripe library wording (`IntegrationError`, "Embedded Checkout objects") reaches
the shopper.

## 4. Not covered here

Completing a payment. That was verified separately for Stripe — full walkthrough
through the hosted page to `cl=thankyou`, contract `committed`, order 247 with
`OXPAID` set (report 02 §5) — and has not been attempted for Mollie. These four
cells prove the checkout reaches a payable state, not that money moves.

## 5. Shop state afterwards

Both payment methods active again, setting back to its default (`on`). The runs
also cancel the test user's leftover pending contracts before each cell, so the
measurements are not polluted by abandoned checkouts from earlier runs.
