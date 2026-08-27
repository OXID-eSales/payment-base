# Sprint 07 — Single active shipping method: auto-assign and hide the shipping blocks

**Branch:** `b-7.4.x` (payment-base) + a one-condition opt-in in `extensions/stripe`
**Module:** `payment-base` — decision logic + classic-checkout behaviour.
One template condition in `stripe` (S5, separate repo/PR) because that module owns a
private copy of the block we are hiding. No other PSP module is touched.
**Engineering requirements:** [`_engeneering_requirements.md`](./_engeneering_requirements.md) — binding for every story below.
**Predecessor:** [Sprint 06 — single active payment method](../../20260826/sprints/sprint-06-single-active-payment-auto-assign.md)
and its [implementation report](../../20260826/reports/01-sprint-06-implementation-report.md) (read §0 first — it
records why sprint 06 does **block replacement, not step skipping**).
**Estimated size:** ~230 LOC production, ~380 LOC tests, 1 new module setting, 0 new class extensions
(both are already in the `extend` map), 2 payment-base template blocks + 1 stripe condition.


> **Revised 2026-08-27 during implementation — read this first.** §1a below claims
> core never persists `sShipSet` on a plain render, and builds the sprint's
> justification on it. That is **wrong**: `Basket::setShipping()` mirrors the id into
> the session, and `PaymentController::getPaymentList()` calls it during
> `parent::render()`. V1 is answered — there is no latent gap, and sprint 06 was
> right. What shipped, and the three things that follow from the correction, are in
> [reports/01-sprint-07-implementation-report.md](../reports/01-sprint-07-implementation-report.md) §0.
> **S6 was not built** (decision D1 was never confirmed); everything else is done.

---

## 1. Why

Sprint 06 removed the dead click on the payment side. The shipping side still has
exactly the same one:

- The payment step ("Zahlung & Versand") renders a `<select name="sShipSet">`
  even when it holds a single `<option>`. Choosing it changes nothing.
- The order page renders a *"Shipping carrier"* block whose pencil leads back to
  that same single-option page.

Requested behaviour, mirroring sprint 06:

- **Auto-assign** the single delivery set as soon as it is known.
- The customer **never sees** the delivery-set selector on the payment step.
- The customer **never sees** the *"Shipping carrier"* block on the order page.

**Where it must live:** `payment-base`. Shipping is not a payment concern and
certainly not a PSP concern — but payment-base already owns both class
extensions (`PaymentController`, `OrderController`) and already owns the
`checkout_flow` setting group, so this is the module that can express the rule
without a second module fighting for the same class chain.

### 1a. What sprint 06 left behind — the `sShipSet` gap

Worth stating up front, because it changes what the assigner is *for*.

Core never persists the chosen delivery set on a plain render:

- `PaymentController::getPaymentList()` resolves the active set via
  `DeliverySetList::getDeliverySetData()` and calls `$oBasket->setShipping($sActShipSet)`
  — but it **does not write `sShipSet` into the session**
  (`source/Application/Controller/PaymentController.php:326-350`).
- Only `changeshipping()` writes it (`:236-243`) — i.e. only if the customer
  actually touched the dropdown.
- `validatePayment()` reads `sShipSet` from the **request first, session second**
  (`:271-272`), and the core payment form
  (`page/checkout/payment.html.twig`, block `change_payment`) **never posts
  `sShipSet`**. Sprint 06's reduced form does not post it either.

So today, a customer who never touches the dropdown reaches
`Payment::isValidPayment($aDynvalue, $shopId, $user, $price, false)` with a falsy
ship-set id. That is pre-existing and out of scope to *fix* — but once the
dropdown is hidden the customer can no longer set it even by accident, so this
sprint's assigner **must write `sShipSet` into the session**. That write is the
substance of the feature; hiding the block is the cosmetic half.

**Verification item (V1):** confirm on the live shop what `isValidPayment()` does
with a falsy ship set today, before and after. If it silently passes, we have
closed a latent gap. If it fails, sprint 06's reduced form has a live defect and
this sprint is its fix — say so in the report either way.

---

## 2. What "exactly one" means

Input is the list OXID has already filtered for the current user, country,
basket and payment availability — `PaymentController::getAllSets()`, which is the
first element of `DeliverySetList::getDeliverySetData()`, keyed by delivery-set
id. A set only appears there when it has at least one usable payment method
**and** `DeliveryList::hasDeliveries()` passes
(`source/Application/Model/DeliverySetList.php`, `getDeliverySetData()`).

A candidate is **auto-assignable** when all of these hold:

| # | Rule | Reason |
|---|------|--------|
| 1 | The filtered set list contains exactly **one** entry | The feature's whole premise. `count() == 1`. |
| 2 | The id is a non-empty string | A numerically indexed array carries no id; an id of `"0"` names a set that does not exist. Same defensive rule as `PaymentCandidateFactory`. |
| 3 | `blPaymentBaseAutoAssignSingleShipping` is on | Merchant kill switch, default **on**. |

There is **no rule-3 analogue of sprint 06's "requires user input"**: a delivery
set has no `OXVALDESC`, collects nothing from the customer and renders no input
fields. And there is **no `oxempty` analogue**: core has no placeholder delivery
set — when nothing is deliverable, `getAllSets()` is empty and core's own
`{% if oView.getAllSets() %}` already hides the block.

There is also **no rule-4 analogue** (`isValidPayment()`): the id we assign came
out of core's own filter one statement earlier. Validation is by construction,
not by a second call. This is the one place where the shipping rule is genuinely
simpler than the payment rule, and the sprint must not invent a check to make
them look symmetric.

---

## 3. Architecture target

### 3a. New in payment-base

```
src/Checkout/
├── Contract/
│   ├── ShippingCandidate.php                   id only — a delivery set asks the customer nothing
│   ├── SingleShippingResolverInterface.php     resolve(array $candidates): ?string
│   ├── SingleShippingAssignerInterface.php     assign(string $shipSetId): bool
│   └── SingleShippingSettingsInterface.php     isAutoAssignEnabled(): bool
├── SingleShippingResolver.php                  rules 1–2 (pure: array in, ?string out)
├── ShippingCandidateFactory.php                OXID DeliverySet models → candidates (final + static)
├── SingleShippingAssigner.php                  the session/basket writes
├── SingleShippingSettings.php                  ModuleSettingService reader, protected readFlag() seam
└── ResolvesSingleShippingMethod.php            the container lookups both controllers share
```

Shape deliberately mirrors sprint 06 file-for-file, so a reader who knows one
knows the other. `SingleShippingResolver` is **pure** — no session, no DB, no
shop API — and therefore unit-testable without a bootstrap.

`ShippingCandidateFactory` is the only place that interrogates OXID delivery-set
models, and every question is wrapped in `try/catch`: the list may contain a
foreign module's model, and a checkout must never break over a set it merely
failed to interrogate. Same reasoning, same shape as `PaymentCandidateFactory`.

`SingleShippingAssigner` writes exactly what core `changeshipping()` writes:

```php
$basket->setShipping(null);                    // core clears first
$session->setVariable('sShipSet', $shipSetId);
$basket->setShipping($shipSetId);              // recomputes delivery cost
```

No new session key. The shop reaches the assigner through protected
`getSession()` / `getBasket()` seams so the write sequence is unit-testable.

### 3b. Class extensions — additive, no new `extend` entries

Both classes are already in payment-base's `extend` map (sprint 06). This sprint
adds methods to the existing extensions; `metadata.php`'s `extend` array is
untouched.

**`PaymentController::render()`** — after `parent::render()`, **before** the
existing single-payment assignment:

```
$this->autoAssignedShipSetId = $this->assignSingleShippingMethod();   // NEW — first
$this->autoAssignedPaymentId = $this->assignSinglePaymentMethod();    // existing
```

> **Ordering is load-bearing, not stylistic.** `SinglePaymentAssigner` reads
> `sShipSet` out of the session and passes it to `isValidPayment()`
> (`src/Checkout/SinglePaymentAssigner.php`). If shipping is assigned second, the
> payment assignment on the very first render still validates against a falsy
> ship set. Shipping first, payment second. Cover it with a test that asserts the
> call order, not just the end state — an end-state test passes on the second
> request either way and would hide the regression.

New view getters: `isSingleShippingAutoAssigned(): bool`, `getSingleShippingId(): string`.
No `getViewData()` override (reserved by `BaseController`).

**`OrderController`** — one more memoised view getter,
`isSingleShippingAutoAssigned()`, computed per request from the same filtered
list, so activating a second delivery set brings the block back immediately.
It re-reads the list via `DeliverySetList::getDeliverySetData(sShipSet, user, basket)`
— the order page has no `getAllSets()` of its own.

### 3c. Templates

| Repo | Template | Block | Behaviour |
|---|---|---|---|
| payment-base | `page/checkout/payment.html.twig` | `change_shipping` | Rendered only when `oView.isSingleShippingAutoAssigned()` is false. Nothing replaces it — unlike `change_payment`, nothing outside submits `<form id="shipping">`; it is submitted by its own `onchange`. Dropping it entirely is safe. |
| payment-base | `page/checkout/order.html.twig` | `checkout_order_shipping_carrier` | Rendered only when the getter is false — heading, carrier name and the pencil form together. |
| **stripe** | `.../page/checkout/order.html.twig` | inside `shippingAndPayment` | Its private `<form id="orderShipping">` copy gets the same condition. |

**Why stripe needs its own condition.** Stripe's order template replaces the
whole `shippingAndPayment` section for Stripe payments and never calls
`parent()`, so a payment-base block override cannot reach inside it — the exact
finding sprint 06 hit for the payment half
([report §0](../../20260826/reports/01-sprint-06-implementation-report.md)).
The shipping-carrier form in that file is a hand copy of core's markup and is
subject to the identical problem. Guard it the same way sprint 06 did:

```twig
{% if oView.isSingleShippingAutoAssigned is not defined
      or not oView.isSingleShippingAutoAssigned() %}
```

The `is defined` guard keeps the page alive if payment-base's extension is absent
from the class chain — without it Twig raises a runtime error instead of simply
showing the block. The decision still lives entirely in payment-base; a consumer
that duplicates core markup opts in to it.

**Delivery-cost visibility.** The `#shipSetCost` line lives *inside* the
`change_shipping` block, so hiding the block removes it from the payment step.
The cost is still in the summary sidebar totals and on the order page's basket
totals. Invariant 5 makes this a checked claim, not an assumption.

### 3d. Setting

```php
['name' => 'blPaymentBaseAutoAssignSingleShipping', 'type' => 'bool',
 'value' => true, 'group' => 'checkout_flow'],
```

Separate flag from `blPaymentBaseAutoAssignSinglePayment` — a merchant may well
want one and not the other, and merging them would silently change sprint 06's
verified behaviour. Reuses the existing `checkout_flow` group, so only the field
key is new. Needs `SHOP_MODULE_blPaymentBaseAutoAssignSingleShipping` in **every**
admin lang file (de + en), and it appears only after `oe:module:install` —
`oe:cache:clear` is not enough.

---

## 4. Stories (one commit each)

| # | Story | Deliverable |
|---|---|---|
| **S1** | Resolver + candidate factory | `SingleShippingResolver`, `ShippingCandidate`, `ShippingCandidateFactory`, interfaces, `services.yaml` (`public: true` — the class extensions fetch them at runtime), unit tests. No behaviour change yet. |
| **S2** | Assigner | `SingleShippingAssigner` — core-identical session/basket writes behind protected seams, unit tests. Still no behaviour change. |
| **S3** | Setting + settings reader | `metadata.php` setting, `SingleShippingSettings` with a protected `readFlag()` seam, de/en admin lang keys, metadata test asserting the setting exists. |
| **S4** | Classic checkout | `ResolvesSingleShippingMethod` trait, the `PaymentController` + `OrderController` additions (**shipping assigned before payment**), the two payment-base template blocks, unit tests via testable subclasses, integration tests. |
| **S5** | stripe opt-in | One condition in stripe's order template + an integration test asserting the block is absent on a single-set Stripe order and present on a two-set one. Separate repo, separate PR, additive. |
| **S6** | *Conditional* — skip the step when both halves are auto-assigned | Only if decision **D1** (§7) is confirmed. See the story note below. |
| **S7** | Verification + report | Playwright walkthrough (single-set and two-set shop, flag on and off), completion report in `../reports/`, sprint doc moved to `../done/`, `status.md` updated. |

**S6 note.** With both flags on in a one-payment/one-set shop, the payment step
renders no shipping block and a payment block reduced to a bare hidden form: a
page with a heading, a "previous" link and a "next" button, and nothing between
them. That is the first moment when the step genuinely has nothing for the
customer — sprint 06's reason for *not* skipping it (the shipping choice lives
there) no longer applies. S6 would forward `cl=payment` → `cl=order` **only when
both auto-assignments succeeded in the same request**. No loop is possible: both
pencils on the order page are hidden in that state. Do not build S6 before D1 is
answered.

---

## 5. TDD plan — failing tests first

**payment-base `tests/Unit/Checkout/`**

- `SingleShippingResolverTest`
  - empty list → `null`
  - two sets → `null`
  - one set → returns its id
  - one set, numeric key and a model whose `getId()` throws → `null`
  - one set, id `''` → `null`
- `ShippingCandidateFactoryTest` — string keys win over `getId()`; numeric key falls back to `getId()`; a model that throws is skipped, not fatal
- `SingleShippingAssignerTest` — writes `sShipSet` **and** calls `setShipping()` in core's order (`null` first), returns `true`; empty id → nothing written, `false`; a throwing session → `false`, no exception escapes
- `SingleShippingSettingsTest` — flag true/false, and `false` when the setting service throws

**payment-base `tests/Unit/Eshop/Application/Controller/`**

- `PaymentControllerTest` (testable subclass; OXID controllers have no constructor DI)
  - disabled setting → no shipping assignment
  - resolver returns `null` → no assignment
  - happy path → assigner called once with the resolved id
  - **`shippingAssignedBeforePayment`** — record call order on a shared spy and assert shipping precedes payment (§3b)
  - existing sprint-06 assertions unchanged — this is the LSP regression net
- `OrderControllerTest` — `isSingleShippingAutoAssigned()` true/false mirrors the resolver; memoised (resolver called once for two getter calls)

**payment-base `tests/Integration/Checkout/`**

- `SingleShippingAutoAssignTest` — one active delivery set: drive `cl=payment`,
  assert `sShipSet` is in the session and the basket's shipping matches; activate
  a second set, assert nothing is assigned.
- `SingleShippingStepTemplateTest` — the rendered payment step contains no
  `name="sShipSet"` **and** does contain the payment form (upper-bound assertions
  pass on a page that failed to render — pair every "absent" with a "present").
- `SingleShippingOrderTemplateTest` — the order page omits the carrier block with
  one set and shows it with two.

No bare `markTestSkipped()`. Gate on `@group requires-db` so a missing
prerequisite hard-fails the container boot instead of faking a green run.

**stripe (S5)** — integration test on the module's order template, mirroring
`SinglePaymentOrderTemplateTest`.

**E2E (S7)** — Playwright: single-set shop walks basket → address → payment step
(no carrier select) → order page (no carrier block) → placed order; two-set shop
unchanged; both flags off ⇒ old behaviour. Reuse the
`single-payment-matrix.spec.ts` harness — it already drives the flag matrix.

---

## 6. Invariants

1. **≥2 delivery sets ⇒ byte-identical behaviour.** The regression net for the
   whole sprint. Consumer unit/integration counts in stripe, paypal, mollie,
   opalreturns and OPC must be unchanged.
2. **Shipping is assigned before payment**, in the same request (§3b).
3. **No provider names.** No `'stripe'` / `'paypal'` / `'mollie'` literal and no
   PSP SDK import anywhere in `src/Checkout/`, and no payment concept in the
   shipping decision.
4. **No new session key.** Only core's existing `sShipSet` is written, with
   core's own `setShipping(null)` → `setShipping($id)` sequence.
5. **Delivery cost stays visible.** Hiding `change_shipping` removes the
   `#shipSetCost` line from the payment step; the cost must still appear in the
   summary sidebar and in the order-page totals. Screenshot-verified.
6. **Sprint 06 stays untouched.** No edit to `SinglePayment*` or
   `PaymentCandidate*` beyond the render-order change, and its test count does
   not drop (see D2).
7. **The order actually places.** A hidden selector that leaves `sShipSet` unset
   would be worse than the dead click. V1 (§1a) is a blocking check.

---

## 7. Risks, open questions, decisions

- **D1 — skip the payment step when both halves are auto-assigned?**
  *Recommendation: yes, as S6, after S1–S5 are green and verified.* It is the
  literal reading of the request ("hide from the checkout process"), the objection
  that killed it in sprint 06 (the step carries shipping) is exactly what this
  sprint removes, and no redirect loop is reachable because both pencils are
  hidden. The cost is a second behaviour change in the same release. **Decide
  before S6 starts; do not build it speculatively.**

- **D2 — deduplicate the two families?** `SinglePayment*` and `SingleShipping*`
  are the same shape, and a reviewer will ask. *Recommendation: no, not in this
  sprint.* The payment rules (`oxempty`, `requiresUserInput`, `isValidPayment()`)
  have no shipping analogue, so a shared abstraction would be a resolver with
  three rules two of which are always-true for one caller. It would also mean a
  non-additive rewrite of code verified live eight days ago, against the
  additive-only requirement. If the duplication is still bothering anyone after
  both are live, extract a shared `SingleChoiceResolver` in a dedicated cleanup
  sprint with the two behaviours already pinned by tests.

- **Order-overview completeness (BGB §312j / order-review expectations).**
  Sprint 06 left this open for the payment line; for shipping it is sharper —
  the carrier and its cost are part of what a German order review is expected to
  restate, and delivery cost is a price component. *Recommendation:* on the
  **order page**, keep a **static, non-clickable carrier line** and drop only the
  pencil; do the full hide on the **payment step**, where the same information is
  in the sidebar. That is one Twig condition either way. **Blocks S7 sign-off,
  and it should settle sprint 06's identical open question at the same time.**

- **One set is not "no choice" for the shop owner.** A shop can have several
  delivery sets that happen to collapse to one for *this* basket/country. The
  block reappears for the next basket. That is correct and intended, but it means
  a merchant may see the selector come and go — worth one line in the release
  notes.

- **`getAllSets()` is only populated after `getPaymentList()`** (core memoises
  both in one call, `PaymentController.php:359-368`). Reading it before
  `parent::render()` returns an empty list. The assignment runs after
  `parent::render()` for exactly this reason — same as sprint 06.

---

## 8. Out of scope

- one-page-checkout. OPC has its own Presta-style shipping step
  (`views/twig/frontend/checkout/components/steps/shipping.html.twig`) and
  already auto-selects the first set when `sShipSet` is empty
  (`src/Service/BasketService.php`). Sprint 06 dropped its OPC story for the same
  reason. If OPC should also hide the step, that is its own sprint in its own repo.
- Auto-selecting a *preferred* set when several are available (cheapest, fastest, default).
- Delivery **address** steps — untouched, and a different concern entirely.
- Admin UI beyond the one checkbox.
- Fixing the pre-existing falsy-`sShipSet` path in core's `validatePayment()`
  beyond what V1 requires us to observe.

---

## 9. Definition of Done

- [ ] V1 answered in the report: what `isValidPayment()` does with a falsy ship set, before and after
- [ ] D1 and D2 decided and recorded; the order-overview question settled for **both** shipping and payment
- [ ] `composer phpcs`, `composer phpstan` (level max), `composer phpmd`, `phpunit` Unit + Integration green in payment-base
- [ ] PHPMD/PHPStan baselines **not** grown
- [ ] Consumer suites green with **identical test counts** to pre-sprint (stripe, paypal, mollie, opalreturns, OPC)
- [ ] Single-set shop: no carrier select on the payment step, no carrier block on the order page, order places successfully — verified with a Stripe payment **and** with `oxidinvoice` (non-PSP proof)
- [ ] Two-set shop: unchanged, screenshot-compared
- [ ] Kill switch verified: `blPaymentBaseAutoAssignSingleShipping` off ⇒ old behaviour, with the payment flag left on
- [ ] Both flags on in a one-payment/one-set shop: order places (this is the S6/D1 state)
- [ ] Delivery cost visible in the sidebar and order totals (invariant 5)
- [ ] Playwright walkthrough report in `../reports/`
- [ ] `status.md` updated, sprint doc moved to `../done/` with a completion report
