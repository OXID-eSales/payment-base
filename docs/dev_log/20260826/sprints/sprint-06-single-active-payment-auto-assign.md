# Sprint 06 — Single active payment method: auto-assign and hide the payment blocks

**Branch:** `b-7.4.x` (payment-base; consumer story in `one-page-checkout`)
**Module:** `payment-base` — decision logic + classic-checkout behaviour.
Consumer wiring in `one-page-checkout` (S5). No PSP module is touched.
**Engineering requirements:** [`_engeneering_requirements.md`](./_engeneering_requirements.md) — binding for every story below.
**Estimated size:** ~260 LOC production, ~420 LOC tests, 1 new module setting, 2 new class extensions, 2 template blocks.

> **Revised 2026-08-26 after review — read this first.** §3b below describes skipping the
> payment step with a redirect. That is **not** what was built, and it was wrong: the step
> also carries the delivery-set selector, and its "next" button submits the payment form by
> id from outside the form. What ships is *block replacement* — the method is assigned while
> the step renders and the selection block is reduced to the bare `<form id="payment">`.
> The implemented design, and the Stripe-template finding that came with it, are in
> [reports/01-sprint-06-implementation-report.md](../reports/01-sprint-06-implementation-report.md) §0.

---

## 1. Why

If a shop offers **exactly one** payment method, the checkout still stops at the
payment step so the customer can select the only radio button there is, and the
order page still offers a "change payment method" block that can only lead back
to that same single-choice page.

That is a dead click and a dead page. Requested behaviour:

- **Auto-assign** the single method as soon as it is known.
- The customer **never sees** the *"select payment"* block (payment step).
- The customer **never sees** the *"Selected payment"* block on the checkout /
  order page (the method + its "change" button).

**Where it must live:** `payment-base`. Not in Stripe, not in PayPal, not in
Mollie — the rule is shop-level checkout behaviour.

**What it must cover:** *all* payment methods, including ones that are **not**
payment-base/PSP extensions — plain `oxidinvoice` (invoice), `oxidcashondel`
(pay on arrival / cash on delivery), `oxidprepayment`, and any third-party
method that only exists as an `oxpayments` row. Therefore the decision is
derived from **OXID's resolved payment list**, never from a PSP handler
registry, and the feature works in a shop with no PSP module installed.

---

## 2. What "exactly one" means

Input is the payment list OXID has already filtered for the current user,
delivery set and basket price — `PaymentController::getPaymentList()`, which is
`DeliverySetList::getDeliverySetData()` output keyed by payment id.

A candidate is **auto-assignable** when all of these hold:

| # | Rule | Reason |
|---|------|--------|
| 1 | The filtered list contains exactly **one** entry | The feature's whole premise. `count() == 1`. |
| 2 | The id is not `oxempty` | `oxempty` is core's "no payment possible / other-country order" placeholder, not a method. |
| 3 | The method needs **no user input** — `oxpayments__oxvaldesc` is empty | `oxiddebitnote` asks for bank data; auto-assigning it would skip fields the order cannot be placed without. Such a shop keeps the normal payment step. |
| 4 | `Payment::isValidPayment()` passes for the current basket/user/ship-set | We assign *through* core validation, never around it. |

Rules 1–3 decide; rule 4 executes. If 4 fails we do **not** redirect — core's
`payerror` path renders the payment step with the error, exactly as today.

---

## 3. Architecture target

### 3a. New in payment-base

```
src/Checkout/
├── Contract/
│   ├── SinglePaymentResolverInterface.php     resolve(array $paymentList): ?string
│   ├── SinglePaymentAssignerInterface.php     assign(string $paymentId): bool
│   └── SinglePaymentSettingsInterface.php     isAutoAssignEnabled(): bool
├── SinglePaymentResolver.php                  rules 1–3 (pure, no session, no DB writes)
├── SinglePaymentAssigner.php                  rule 4 + session/basket writes
├── PaymentInputRequirement.php                final + static: requiresUserInput(Payment): bool
└── ModuleSinglePaymentSettings.php            ModuleSettingService reader, protected readFlag() seam
```

`SinglePaymentResolver` is deliberately **pure**: array in, `?string` out. It is
the one piece both the classic checkout and one-page-checkout consume, and it is
trivially unit-testable without a shop bootstrap.

`SinglePaymentAssigner` writes exactly what core `validatePayment()` writes on
success — `paymentid`, `dynvalue` (empty array), `sShipSet` on the basket,
`_selected_paymentid` deleted — so no downstream code can tell the difference
between an auto-assignment and a customer click.

### 3b. Class extensions (metadata `extend`, additive)

```php
\OxidEsales\Eshop\Application\Controller\PaymentController::class
    => \OxidEsales\PaymentBase\Eshop\Application\Controller\PaymentController::class,
\OxidEsales\Eshop\Application\Controller\OrderController::class
    => \OxidEsales\PaymentBase\Eshop\Application\Controller\OrderController::class,
```

Both extend `*_parent` and call `parent::` first (never the core class directly —
that would silently drop other modules from the chain).

**`PaymentController::render()`** — after `parent::render()`:

```
if (!enabled)                          → return parent result
if (fnc parameter present)             → return parent result   (a POST is in flight)
if (payment error set)                 → return parent result   (show the error page)
if (resolver->resolve(list) === null)  → return parent result   (0, 2+, oxempty, needs input)
if (!assigner->assign(id))             → return parent result   (isValidPayment failed)
redirect(cl=order, 302)                                          (skip the step)
```

**`OrderController`** — adds one view getter, `isSinglePaymentAutoAssigned()`,
answering "was the payment block suppressed?". It re-uses the same resolver so
there is a single source of truth. No `getViewData()` override (reserved by
`BaseController`).

### 3c. Template blocks (metadata `blocks`, additive)

| Template | Core block | Override behaviour |
|---|---|---|
| `page/checkout/order.html.twig` | `checkout_order_payment_method` | Rendered only when `oView.isSinglePaymentAutoAssigned()` is false. This is the *"Selected payment"* block — heading, method description **and** the "change" form/button. |
| `page/checkout/order.html.twig` | `shippingAndPayment` | Payment half of the shipping+payment strip is suppressed the same way; shipping stays untouched. |

The payment step's *"select payment"* block needs no template work — the
customer is redirected before it renders. Direct navigation to `cl=payment`
lands on the same redirect, so there is no way to reach the single-choice page.

### 3d. Setting

```php
['name' => 'blPaymentBaseAutoAssignSinglePayment', 'type' => 'bool',
 'value' => true, 'group' => 'checkout_flow'],
```

Default **on** — that is the requested behaviour — and it only ever fires when
the list has exactly one entry, so a shop with ≥2 methods cannot be affected.
The flag is the kill switch for shops that want the step kept. Needs
`SHOP_MODULE_GROUP_checkout_flow` + `SHOP_MODULE_blPaymentBaseAutoAssignSinglePayment`
in **every** admin lang file (de + en), and it only appears after
`oe:module:install` — `oe:cache:clear` is not enough.

---

## 4. Stories (one commit each)

| # | Story | Deliverable |
|---|---|---|
| **S1** | Resolver + input requirement | `SinglePaymentResolver`, `PaymentInputRequirement`, interfaces, `services.yaml` (resolver `public: true` — OPC and the class extensions fetch it at runtime), unit tests. No behaviour change yet. |
| **S2** | Assigner | `SinglePaymentAssigner` — `isValidPayment()` gate + core-identical session/basket writes, unit tests with a mocked `Payment`. |
| **S3** | Setting + settings reader | `metadata.php` setting, `ModuleSinglePaymentSettings` with a protected `readFlag()` seam, de/en admin lang keys, metadata test asserting the setting exists. |
| **S4** | Classic checkout | `PaymentController` + `OrderController` extensions, the two template blocks, unit tests via testable subclasses, integration test for the redirect. |
| **S5** | one-page-checkout consumer | `PaymentMethodService::getAvailablePaymentMethods()` result is passed through the resolver; when it returns an id, OPC pre-selects it, marks the section satisfied and the `payment-method` step + its summary row are not rendered. Separate repo, separate PR, additive. |
| **S6** | Verification + report | Playwright walkthrough (single-method shop and multi-method shop), completion report in `../reports/`, sprint doc moved to `../done/`. |

S5 is the reason the resolver is pure and public: OPC has its own controller,
its own JSON endpoint and its own Twig, so it cannot inherit the classic-checkout
behaviour — it must call the same decision function.

---

## 5. TDD plan — failing tests first

**payment-base `tests/Unit/Checkout/`**

- `SinglePaymentResolverTest`
  - empty list → `null`
  - two methods → `null`
  - one method, no `oxvaldesc` → returns its id
  - one method, `oxvaldesc` set (`oxiddebitnote`) → `null`
  - one method, id `oxempty` → `null`
  - list keyed by id with core methods (`oxidinvoice`, `oxidcashondel`) → id returned (**non-PSP coverage, rule from §1**)
- `PaymentInputRequirementTest` — empty / whitespace / `dynvalue` field list in `oxvaldesc`
- `SinglePaymentAssignerTest` — `isValidPayment()` true → session keys written, `_selected_paymentid` deleted, returns `true`; false → nothing written, returns `false`
- `ModuleSinglePaymentSettingsTest` — flag true/false, and `false` when the setting service throws

**payment-base `tests/Unit/Eshop/Application/Controller/`**

- `PaymentControllerTest` (testable subclass; OXID controllers have no constructor DI)
  - disabled setting → no redirect
  - `fnc` present → no redirect
  - `payerror` set → no redirect
  - resolver returns `null` → no redirect
  - assigner returns `false` → no redirect
  - happy path → redirect to `cl=order`, called exactly once
- `OrderControllerTest` — `isSinglePaymentAutoAssigned()` true/false mirrors the resolver

**payment-base `tests/Integration/Checkout/`**

- `SinglePaymentAutoAssignTest` — seed a shop state with one active payment,
  drive `cl=payment`, assert redirect + `paymentid` in session; then activate a
  second payment and assert the step renders normally. No `markTestSkipped()`
  without a `@group requires-db` gate — a silent skip is a fake green.

**E2E (S6)** — Playwright: single-method shop walks basket → address → order
page with no payment step and no "Selected payment" block; multi-method shop is
unchanged.

---

## 6. Invariants

1. **≥2 methods ⇒ byte-identical behaviour.** The regression net for the whole
   sprint. Consumer unit/integration counts in stripe, paypal, mollie and OPC
   must be unchanged.
2. **No provider names.** No `'stripe'` / `'paypal'` / `'mollie'` literal and no
   PSP SDK import anywhere in `src/Checkout/`.
3. **Assignment always goes through `isValidPayment()`.** A method that cannot
   legally be used is never assigned, single or not.
4. **No new session key.** Only core's existing keys are written.
5. **Surcharges stay visible.** A payment surcharge (`oxpayments__oxaddsum`)
   still shows up in the basket totals even though the block is hidden.
6. **No redirect loop.** `cl=payment` → `cl=order` only, and only when the
   assignment succeeded; a failed assignment always renders the payment step.

---

## 7. Risks / open questions

- **Order-overview completeness.** Hiding the *"Selected payment"* block removes
  the payment method from the final order overview. For DE shops (BGB §312j /
  order-review expectations) the safer variant is to keep a **static, non-clickable**
  method line and drop only the "change" button. The sprint implements the
  requested full hide; if legal review objects, the fix is one Twig condition —
  keep the description, suppress the form. **Decide before S6 sign-off.**
- **Payment surcharge transparency.** A single method with a surcharge is now
  never explicitly confirmed by the customer. Covered by invariant 5 (totals),
  worth a note in the release notes.
- **`oxiddebitnote`-only shops** keep the payment step by design (rule 3). Not a
  bug — the bank-data fields live there.

---

## 8. Out of scope

- Auto-selecting a *preferred* method when several are available.
- Skipping the shipping/delivery-set step.
- Admin UI beyond the one checkbox.
- Any change inside a PSP module (stripe / paypal / mollie).

---

## 9. Definition of Done

- [ ] `composer phpcs`, `composer phpstan` (level max), `composer phpmd`, `phpunit` Unit + Integration green in payment-base
- [ ] PHPMD/PHPStan baselines **not** grown
- [ ] Consumer suites (stripe, paypal, mollie, opalreturns, OPC) green with **identical test counts** to pre-sprint
- [ ] Single-method shop: no payment step, no "Selected payment" block, order places successfully — verified with `oxidinvoice` **and** with `oxidcashondel` (non-PSP proof)
- [ ] Multi-method shop: unchanged, screenshot-compared
- [ ] Kill switch verified: setting off ⇒ old behaviour
- [ ] Playwright walkthrough report in `../reports/`
- [ ] `status.md` updated, sprint doc moved to `../done/` with a completion report
