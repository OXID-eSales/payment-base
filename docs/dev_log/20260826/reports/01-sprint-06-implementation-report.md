# Sprint 06 — implementation report

**Sprint:** [sprint-06-single-active-payment-auto-assign.md](../sprints/sprint-06-single-active-payment-auto-assign.md)
**Requirements:** [`_engeneering_requirements.md`](../sprints/_engeneering_requirements.md)
**Module:** `payment-base` (branch `b-7.4.x`) — no PSP module and no consumer repo touched.
**State:** S1–S4 done and verified live on `daniil.oxiddev.de`. S5 dropped (already implemented elsewhere, see §4). Revised once after review — the first version skipped the whole payment step, which was wrong; see §0.

---

## 0. Correction after review

The first implementation **redirected past the payment step** when a single method
was available. That was wrong for two reasons found on the live shop:

1. **The step is not only about payment.** It is "Zahlung & Versand" — the
   delivery-set selector (`change_shipping`) lives on the same page. Skipping the
   step took the shipping choice away with it.
2. **The step's "next" button submits the payment form by id.** The button sits
   *outside* the form and does
   `document.querySelector('#payment').requestSubmit()`. Removing the block
   outright would have left the customer with no way forward.

What ships instead: the method is assigned while the step renders, and the
**payment-selection block is replaced by the bare form** — no heading, no radio,
no price, no description; the `<form id="payment">` with the session parameters,
the `validatepayment` action and a hidden `paymentid` stays. The step then shows
the shipping choice alone. The order page's payment block is dropped as before.

Consequences of the correction: no redirect, so the redirect-loop analysis, the
`sShipSet` re-validation parity argument and the "session already holds a payment
id" guard are all moot — the last one is gone (it was suppressing the feature on
the live shop, where the session already carried a payment id from earlier
checkouts).

A second live finding: **the Stripe module's own order-page template replaces the
whole `shippingAndPayment` section** with a private copy (its own `orderPayment`
form) whenever the payment is a Stripe method, and never calls `parent()`. A
payment-base block override cannot reach inside that copy, so
`extensions/stripe/views/twig/extensions/themes/default/page/checkout/order.html.twig`
now honours `oView.isSinglePaymentAutoAssigned()` itself, guarded with
`is defined` so a missing extension shows the block instead of raising a Twig
error. The decision still lives entirely in payment-base; a consumer that
duplicates core markup has to opt in to it.

---

## 1. What now happens

A shop that offers exactly one usable payment method never shows the payment
selection step, and never shows the "payment method" block on the order page.
The method is validated and assigned on the customer's behalf.

The rule is derived from **OXID's own filtered payment list**, so it covers
methods that are not payment-base extensions at all — plain `oxidinvoice`
(invoice), `oxidcashondel` (pay on arrival), `oxidprepayment`, or any
third-party method that exists only as an `oxpayments` row. It works in a shop
with no PSP module installed.

Auto-assignment happens only when **all** of these hold:

| # | Condition | Why |
|---|---|---|
| 1 | the filtered payment list has exactly one entry | the premise |
| 2 | the id is not `oxempty` | core's "no payment possible" placeholder, not a method |
| 3 | the method has no dynamic input fields (`OXVALDESC`) | `oxiddebitnote` collects bank data *on the block we hide* |
| 4 | `Payment::isValidPayment()` passes | assignment goes through the rules, not around them |
| 5 | no payment error is pending | an error is a message the customer has to act on |
| 6 | `blPaymentBaseAutoAssignSinglePayment` is on | merchant kill switch, default **on** |

## 2. Files

**New — the decision (provider-agnostic, no shop API):**
- `src/Checkout/Contract/PaymentCandidate.php` — id + "does it ask the customer anything"
- `src/Checkout/Contract/SinglePaymentResolverInterface.php` / `src/Checkout/SinglePaymentResolver.php` — rules 1–3, pure
- `src/Checkout/PaymentCandidateFactory.php` — OXID payment models → candidates (the only place that interrogates them)
- `src/Checkout/Contract/SinglePaymentAssignerInterface.php` / `src/Checkout/SinglePaymentAssigner.php` — rule 4 + the session writes
- `src/Checkout/Contract/SinglePaymentSettingsInterface.php` / `src/Checkout/SinglePaymentSettings.php` — rule 7
- `src/Checkout/ResolvesSinglePaymentMethod.php` — the container lookups both controllers share

**New — the classic checkout:**
- `src/Eshop/Application/Controller/PaymentController.php` — assigns during render, answers `isSinglePaymentAutoAssigned()` / `getSinglePaymentId()`
- `src/Eshop/Application/Controller/OrderController.php` — answers `isSinglePaymentAutoAssigned()`
- `views/twig/extensions/themes/default/page/checkout/payment.html.twig` — reduces the selection block to the bare form
- `views/twig/extensions/themes/default/page/checkout/order.html.twig` — drops the payment block

**Changed in `stripe` (consumer opt-in, see §0):**
- `views/twig/extensions/themes/default/page/checkout/order.html.twig` — its private copy of the payment block honours the same getter
- `services.yaml` — `RetryCleanupService` and `StripeOrderApiService` made `public: true` (unrelated blocker found on the way, §7)

**Changed:**
- `metadata.php` — two `extend` entries, one setting (`checkout_flow` group)
- `services.yaml` — three services, all `public: true`
- `views/admin_twig/{de,en}/payment_admin_lang.php` — group + field labels
- `tests/bootstrap-unit.php`, `tests/PhpStan/phpstan-bootstrap.php` — stubs for the two virtual parents, `Payment`, `PaymentList`, and the `Registry` methods the new code uses
- `phpstan.neon` — the `oxNew` ignore pattern matched an older PHPStan wording ("Used function oxNew not found") and no longer fired; it now matches both

## 3a. Live verification (`daniil.oxiddev.de`, one usable method: Stripe Wallet)

Both pages fetched over HTTP in a real checkout session:

| Page | Expectation | Result |
|---|---|---|
| `cl=payment` | no radio, no payment-option markup | `type="radio"` × 0, `payment-option` × 0 |
| `cl=payment` | the form the "next" button submits survives | `id="payment"` present, `name="paymentid" value="oe_payments_stripe_wallet"` |
| `cl=payment` | delivery-set selector untouched | `name="sShipSet"` present |
| `cl=order` | payment block gone | `id="orderPayment"` × 0 |
| `cl=order` | shipping block untouched | `id="orderShipping"` present |

Note for the next person: PHP-FPM's opcache had to be dropped
(`docker compose restart php`) before class-level changes took effect, and
`oe:cache:clear` before template ones.

## 3. How the writes stay honest

`SinglePaymentAssigner` writes exactly what core's `validatePayment()` writes on
success — `paymentid`, `dynvalue`, the basket's shipping, and the removal of
`_selected_paymentid` — after `Payment::isValidPayment()` passes. **No new
session key was introduced.**

That parity is what prevents a redirect loop. `OrderController::getPayment()`
re-validates the payment with the session's `dynvalue` and `sShipSet` and
redirects back to `cl=payment` if it fails. Because the assigner validates with
those same values, a successful assignment cannot fail there.
`SinglePaymentAssignerTest::testValidationUsesTheSameInputsTheOrderStepWillUse`
guards it.

## 4. Deviations from the plan

- **S5 (one-page checkout) dropped — already implemented.** OPC folds a
  single-method payment section itself: `assets/js/checkout.js`
  (`PaymentMethodController`) broadcasts `oe:section:phantomize` with reason
  `single-active-method` when `methods.length === 1`, and pre-selects it. The
  same is done for shipping. Adding a payment-base consumer path there would
  duplicate working behaviour. The resolver is nevertheless registered
  `public: true` so OPC can adopt it later if that logic ever moves server-side.
- **The `shippingAndPayment` block override was unnecessary.**
  `checkout_order_payment_method` is nested *inside* it in the apex template, so
  overriding the inner block alone hides the payment half and leaves shipping
  untouched. One override instead of two.
- **No redirect, no request guards.** See §0. The step always renders; only the
  selection block is replaced. The `fnc` guard the sprint planned would have
  broken the normal flow anyway (arriving from the user step, the request still
  reads `cl=user&fnc=changeuser`), and the session guard that replaced it was
  suppressing the feature on the live shop.
- **Setting defaults to on.** The sprint proposed this; it is worth restating why
  it is safe: the code cannot fire unless the list has exactly one entry, so a
  shop with two or more methods behaves identically whatever the flag says.

## 5. Verification

| Gate | Result |
|---|---|
| `composer phpcs` (PSR-12) | clean |
| `composer phpstan` (**level max**) | no errors, baseline not grown |
| `composer phpmd` (`--strict`) | clean, baseline not grown |
| payment-base Unit | **1182** tests / 2579 assertions, 6 skipped (from 1128 / 2498 — **+54**) |
| payment-base Integration | **102** tests / 314 assertions (from 85 / 287 — **+17**) |
| stripe `composer phpcs` | clean (template-only change there) |
| mollie-payment Unit | 518 tests OK |
| paypal Unit | 449 tests OK |
| opalreturns Unit | 353 tests OK |
| one-page-checkout Unit | 316 tests / 775 assertions / 1 error — **identical before and after** this sprint (see §7) |

What the integration tests prove against the running shop, not just in isolation:

- all three services resolve from the compiled container (a private service
  would have been inlined away and the controller extensions would have died on
  a missing id at runtime)
- both controller extensions really are in the class chain — `oxNew()` of core's
  `PaymentController` / `OrderController` returns payment-base's class, with four
  other modules already extending the same controllers
- the real `oxidinvoice` row is auto-assignable and the real `oxiddebitnote` row
  is not, read through OXID's actual `Payment` model
- both template overrides land: rendering the shop's real
  `page/checkout/order.html.twig` and `page/checkout/payment.html.twig` through
  the shop's own renderer shows the payment block/radio when the getter says
  false and not when it says true — while `id="payment"`, `validatepayment`, the
  hidden `paymentid` and `name="sShipSet"` all survive the hidden case

The setting was registered by `oe:module:install extensions/payment-base`, which
also rebuilt the class-extension chain; no deactivate/activate cycle was needed.
The storefront answered 200 afterwards.

The 7 deprecations now reported by the integration suite come from OXID core
(`WidgetControl`, `ShopControl`, `FrontendController` passing null to string
functions) and are triggered by rendering a full page template outside a real
request. They are core's, not this sprint's.

## 6. Still open

- **Clicking through the flow in a browser.** The two pages were verified over
  HTTP (§3a), including that the submit path is intact, but nobody has pressed
  "Weiter" and placed an order end-to-end on the single-method shop. Two active
  methods are configured in the DB (`oe_payments_mollie`,
  `oe_payments_stripe_wallet`); only Stripe Wallet survives the filter for this
  basket and delivery set, which is why the feature fires.
- **The other providers' order templates.** Only stripe duplicates the payment
  block (`grep -l 'id="orderPayment"'` finds stripe and stripe_bkp, not mollie or
  paypal), so only stripe needed the opt-in. If a future provider copies that
  markup, it has to opt in the same way.
- **Order-overview completeness (the open question from the sprint).** The block
  is hidden as specified, which removes the payment method from the final order
  overview. For DE shops the safer variant is a static, non-clickable method line
  with only the pencil removed. That is one condition in
  `views/twig/extensions/themes/default/page/checkout/order.html.twig` — override
  `checkout_order_payment_method_form` instead of the whole block. Still to be
  decided.

## 7. Pre-existing problems found on the way (not caused here)

- **The stripe unit suite cannot be built in this shop.** Both documented
  invocations fail before the first test:
  `-c extensions/stripe/tests/phpunit.xml` dies with
  `Class "OxidEsales\Payments\PayPal\Controller\PaymentController_parent" not found`
  — OXID's `ModuleChainsGenerator` recursing through the five-module
  `PaymentController` chain under PHPUnit's autoloader — and running from the
  module directory dies with `Cannot redeclare Safe\array_replace_recursive()`
  (the module's own `vendor/` colliding with the shop's). Verified pre-existing by
  removing this sprint's `extend` entries: the same error appears, one chain level
  shallower.
- **Stripe's `RetryCleanupService` was private but fetched by id — FIXED here.**
  It surfaced as `STRP-105: Payment/Order page cleanup failed` on every checkout page
  and then as `createCheckoutSession failed` → *"Payment processing failed. Please try
  again."*: **checkout was dead**. Cause: `services.yaml` re-declares the service after
  the `src/Stripe/Service/*` resource sweep (to inject `$logger`), and the
  re-declaration re-inherits `_defaults: public: false`. A runtime `Container::get()`
  is not a compile-time reference, so Symfony inlined the service into its only real
  consumer (the retry event handler) and deleted the id. Latent, not caused by this
  sprint — but this sprint's `oe:module:install` / `oe:cache:clear` recompiled the
  container, which is what made it bite. Fix: `public: true`.
- **`StripeOrderApiService` had the identical defect, silently.** Fetched by id from the
  `Order` model extension for the admin order tab, referenced nowhere at compile time
  (so removed outright), and its caller catches `Throwable` — so it showed up as an
  empty Stripe transaction history rather than an error. Fixed the same way.
  Those two were the only offenders: all 14 ids fetched through
  `ServiceContainer::getServiceFromContainer()` were audited, the other 12 are public.
  Verified by resolving both ids from the compiled container and by loading both
  checkout pages with an empty log tail afterwards.
- **`one-page-checkout` `PaymentControllerTest::testPaymentControllerRedirectsWhenReplaceMinibasketEnabled`**
  errors from the same recursion. Also verified pre-existing the same way; test
  and assertion counts are identical before and after this sprint. The extension
  chain does build correctly at runtime — the integration tests instantiate both
  controllers through it.
