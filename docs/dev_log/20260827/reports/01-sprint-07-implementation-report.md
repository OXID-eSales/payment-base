# Sprint 07 — implementation report

**Sprint:** [sprint-07-single-active-shipping-method.md](../done/sprint-07-single-active-shipping-method.md)
**Requirements:** [`_engeneering_requirements.md`](../sprints/_engeneering_requirements.md)
**Modules:** `payment-base` (branch `sprint-07-single-active-shipping`) + one template condition and two E2E specs in `stripe`.
**State:** S1–S5 and S7 done, E2E green against the tunnelled local shop. **S6 not built** — it was gated on decision D1, which is answered below and is the user's call. Revised once during implementation: §0.

---

## 0. Correction during implementation — the sprint's premise was wrong

The sprint doc's §1a claimed core never persists the chosen delivery set on a
plain render, and built the whole justification on it. That is **false**, and it
was found by actually answering V1 instead of assuming it.

`Basket::setShipping()` mirrors the id into the session:

```php
public function setShipping($sShippingSetId = null)
{
    $this->_sShippingSetId = $sShippingSetId;
    Registry::getSession()->setVariable('sShipSet', $sShippingSetId);   // <-- this
}
```

and `PaymentController::getPaymentList()` calls it during `parent::render()`
(`source/Application/Controller/PaymentController.php:343`). So `sShipSet` is
already in the session before either auto-assignment runs. Sprint 06's code
comment said exactly this and was right; the sprint 07 plan contradicted it and
was wrong.

**V1 is therefore answered: there is no latent gap.** For the record,
`isValidPayment()` *would* refuse a falsy ship set — it takes the `else` branch,
sets `_iPaymentError = -2` and returns false
(`source/Application/Model/Payment.php`) — but that branch is not reachable from
the payment step, because core has already written the value.

Three things changed as a result:

1. **The assigner corrects, it does not write.** Re-writing a value core already
   has right would force a basket recalculation on every render of a
   single-carrier shop, for no change at all. It now returns early when the
   session already names the resolved set. It still exists, and is still worth
   having: it makes this module's decision authoritative if another module in
   the `PaymentController` chain ever suppresses or alters core's write, which is
   what makes hiding the selector safe.

2. **The write mirrors `changeshipping()` exactly, including `onUpdate()`** —
   which the first implementation omitted. Without that flag the basket keeps a
   stale delivery cost, and an end-value assertion on the session would not have
   noticed. The test pins the call sequence instead.

3. **The ordering invariant survives but is narrower than the sprint claimed.**
   Shipping is still assigned before payment, but both orders normally leave the
   same session behind. It matters only on the one request where the shipping
   assignment has something to correct — running it second would validate the
   payment against the value it is about to replace. The comment and the test
   docblock now say that, rather than claiming a live bug.

A fourth finding, in the consumer direction: **`single-payment-matrix.spec.ts`
contained an assertion this sprint invalidates** — "the shipping block always
stays", `#orderShipping` and `select[name="sShipSet"]` unconditionally present.
True when written, false now on a one-carrier shop. Defused rather than deleted;
see §5.

---

## 1. What now happens

A shop where exactly one delivery set survives OXID's filter never shows the
carrier selector on the payment step, and never shows the "shipping carrier"
block on the order page. The set is assigned on the customer's behalf.

The rule is derived from **OXID's own filtered active-set list**
(`DeliverySetList::getDeliverySetData()`), so it covers every delivery set the
shop has, whatever created it, and works with no PSP module installed.

Auto-assignment happens when **all** of these hold:

| # | Condition | Why |
|---|---|---|
| 1 | the filtered set list has exactly one entry | the premise |
| 2 | the id is a non-empty string | an empty id names a set that does not exist |
| 3 | `blPaymentBaseAutoAssignSingleShipping` is on | merchant kill switch, default **on** |

Deliberately three rules, not five. There is no `oxempty` analogue among
delivery sets (an undeliverable basket yields an empty list, which core already
hides) and no "requires user input" analogue (a set has no `OXVALDESC` and
renders no fields). There is no `isValidPayment()` analogue either: the id came
out of core's own filter one statement earlier, so validation is by
construction. Inventing checks to make the two families look symmetric would
have been dead code, and the sprint said so up front.

## 2. Files

**New in payment-base — the decision (provider-agnostic, no shop API):**
- `src/Checkout/Contract/ShippingCandidate.php` — a delivery set, reduced to its id
- `src/Checkout/Contract/SingleShippingResolverInterface.php` / `src/Checkout/SingleShippingResolver.php` — rules 1–2, pure
- `src/Checkout/ShippingCandidateFactory.php` — OXID models → candidates (the only place that interrogates them)
- `src/Checkout/Contract/SingleShippingAssignerInterface.php` / `src/Checkout/SingleShippingAssigner.php` — the session/basket correction
- `src/Checkout/Contract/SingleShippingSettingsInterface.php` / `src/Checkout/SingleShippingSettings.php` — rule 3
- `src/Checkout/ResolvesSingleShippingMethod.php` — the container lookups both controllers share

**Changed in payment-base:**
- `src/Eshop/Application/Controller/PaymentController.php` — assigns the set, exposes `isSingleShippingAutoAssigned()` / `getSingleShippingId()`
- `src/Eshop/Application/Controller/OrderController.php` — exposes `isSingleShippingAutoAssigned()`
- `views/twig/.../page/checkout/payment.html.twig` — `change_shipping` block
- `views/twig/.../page/checkout/order.html.twig` — `checkout_order_shipping_carrier` block
- `metadata.php`, both admin lang files, `services.yaml`, both test bootstraps

**Changed in stripe:**
- `views/twig/.../page/checkout/order.html.twig` — the private carrier form opts in
- `tests/Integration/Checkout/SingleShippingOrderTemplateTest.php` — new
- E2E submodule: `single-shipping-matrix.spec.ts` new, `single-payment-matrix.spec.ts` defused

`metadata.php`'s `extend` array is **unchanged** — both controllers were already
in it from sprint 06. No new class extension was needed.

## 3. Why stripe needed its own condition

Same trap sprint 06 hit, for the same reason. This module replaces the whole
`shippingAndPayment` section with a private copy for Stripe payments and never
calls `parent()`, so payment-base's block override cannot reach inside it. The
carrier form in that copy is hand-copied core markup and had the identical
problem. It now carries the condition itself, guarded with `is defined` so a
missing payment-base extension shows the block instead of raising a Twig error.

The decision still lives entirely in payment-base. A consumer that duplicates
core markup has to opt in to it.

## 4. Verification

**Gates (payment-base):** `phpcs`, `phpstan --level max`, `phpmd --strict` all
clean. **Neither baseline grew** — phpstan-baseline.neon still has its 3 OXID
virtual-parent entries, phpmd.baseline.xml still has none added. One PHPStan
finding appeared during S4 (`Cannot use array destructuring on mixed`) and was
fixed in the code, not suppressed: `readAvailableDeliverySetList()` now checks
the shape of `getDeliverySetData()`, which is right anyway — it returns `null`
outright when there is no user.

**Test counts:**

| Suite | Before | After |
|---|---|---|
| payment-base Unit | 1182 | **1236** |
| payment-base Integration | 95 | **104** |
| payment-base Integration-renderer | 7 | **16** |
| stripe Integration | 92 | **96** |

**Consumers, identical before and after (invariant 1):** mollie 582/1502,
paypal 449/798, opalreturns 353/800, one-page-checkout 316/775 with the same
single pre-existing error.

**Mutation-checked, not just green.** The ordering test was confirmed red by
swapping the two assignment lines in `PaymentController::render()` — it fails
with `['payment','shipping']` — and then restored. Without that check "shipping
first" would have been an untested comment.

**Live shop, server-side.** Rather than trust the unit tests alone, the decision
was driven against the running local shop with a real user, a real basket and
real `DeliverySetList` data:

| shop state | effective sets | resolver | setting | selector |
|---|---|---|---|---|
| as found (4 active sets) | 2 | `null` | on | **shown** |
| all but `oxidstandard` deactivated | 1 | `'oxidstandard'` | on | **hidden** |
| same, kill switch off | 1 | `'oxidstandard'` | off | **shown** |
| same, kill switch on again | 1 | `'oxidstandard'` | on | **hidden** |

The four delivery sets were restored to their exact recorded pre-test state
(all `OXACTIVE=1`) and the flag left on. Worth noting the middle finding: this
shop has **four** active delivery sets but only **two** survive the filter for a
normal basket, and only **one** payment method does — which is why sprint 06's
shortcut is live here and sprint 07's is not.

**E2E: executed and green.** Initially blocked — the shop's `sShopURL` read
`https://pay1.oxid.dev` and Playwright's requests to `localhost.local` were
redirected off-box, so the suite was not run rather than risk exercising a
shared host. The environment was then repointed at `https://daniil.oxiddev.de`
(the same local shop, tunnelled), which the container's own `getShopUrl()`
confirms, and all four cells were driven against it:

| spec | delivery sets | shipping setting | selector (options) | `#orderShipping` | `#orderPayment` | submits |
|---|---|---|---|---|---|---|
| shipping matrix | 2 effective | on | 1 (2) | 1 | 0 | 10 |
| shipping matrix | 1 effective | on | **0** | **0** | 0 | 9 |
| shipping matrix | 1 effective | off | 1 (1) | 1 | 0 | 10 |
| payment matrix (mollie/on, `EXPECT_SHIPPING=hidden`) | 1 effective | on | 0 | — | 0 | 9 |

All passed, no messages displayed in any cell. Row 1 is the shortcut correctly
staying inert. Row 3 is the kill switch, and shows the dead click the sprint
removes: a dropdown with exactly one option. Row 2 is also the **D1 state** —
both blocks hidden — and the order page still rendered nine submit controls,
which is the closest thing to evidence D1 is safe that can be had without
building it.

The browser numbers match the server-side probe exactly, and the run also
settles a question the probe could not: the tunnel really does serve this
container, since the hidden payment block in every row is sprint 06 code that
only exists in this checkout.

Two environment notes for whoever runs it next. The specs' default product
`34beb6d63dd96d36ed6875c09e300b02` is a **variant parent**, so `#toBasket` never
becomes visible and the run dies before reaching checkout; pass a non-variant
`TEST_PRODUCT_ANID` (`0757c381b5c2efea14b10d34822c67ed` works). And the four
`oxdeliveryset` rows were restored to their exact captured pre-test state, with
the flag left on — though note that state was *already* `294c2e89…=0` when this
run began, having changed since the earlier server-side probe, so it is worth
confirming the shop's delivery sets are as intended before drawing conclusions
from a future run.

**Also unrun: the stripe Unit suite**, which cannot be built in this shop —
`Class "OxidEsales\Payments\Mollie\Controller\PaymentController_parent" not
found` during `ModuleChainsGenerator::createClassExtension`. Pre-existing and
already recorded; it names no sprint-07 symbol, and the new payment-base
integration test `oxNew`s that same controller successfully, so the runtime
chain is fine. Stripe's Integration suite runs and is green.

## 5. The E2E landmine

`single-payment-matrix.spec.ts` asserted `select[name="sShipSet"]` and
`#orderShipping` were present unconditionally — "the shipping block always
stays". On a one-carrier shop with sprint 07 on, both are now absent, and the
payment spec would have failed for a shipping reason.

Both assertions now read an `EXPECT_SHIPPING` variable defaulting to `shown`,
which is the behaviour of every shop with two or more carriers — including the
one the recorded four-cell matrix in that spec's footer was measured on, so the
recorded results stand. Its footer now says so explicitly.

## 6. Decisions

**D1 — skip the payment step when both halves are auto-assigned?**
**Not built. This is the user's call and it was never confirmed.** The sprint
said "do not build speculatively" and that still holds: it is a second, larger
behaviour change (a redirect, not a hidden block), and the case for it rests on
the step being empty — which the renderer test
`testBothBlocksCanBeHiddenAndTheSubmitPathSurvives` now demonstrates is *almost*
true: what remains is the bare `<form id="payment">` plus the step's own
navigation. The E2E run reached that state on a real shop (§4, row 2) and the
order page stayed placeable. Everything S6 needs is in place and green; it is
one guarded redirect away. My recommendation is unchanged — do it, as its own commit, once
someone says so.

**D2 — deduplicate `SinglePayment*` and `SingleShipping*`?** **No, as
recommended, and the implementation strengthened the case.** The shipping family
ended up with three rules against the payment family's six, no user parameter on
`assign()`, an idempotence guard the payment side does not want, and no
`isValidPayment()` step. A shared abstraction would be a resolver whose rules
are mostly no-ops for one caller. Revisit only after both are live, with a
dedicated cleanup sprint and the behaviours already pinned.

**Order-overview completeness (BGB §312j).** **Still open, and now the only
thing blocking a clean DoD.** The sprint implements the full hide, as asked. My
recommendation stands and is unchanged by the implementation: keep a **static,
non-clickable carrier line** on the *order page* and drop only the pencil; keep
the full hide on the *payment step*, where the sidebar carries the same
information. It is one Twig condition in two files (payment-base's block and
stripe's private copy), and it should settle sprint 06's identical open question
for the payment line at the same time. A shipping carrier and its cost are a
price component in a German order review, so this is a sharper question than it
was for payment.

## 7. Open / not done

- **S6** — gated on D1 above.
- **Order-overview decision** — above. Blocks DoD sign-off.
- **E2E execution** — done, all four cells green (§4). No longer open.
- **`SHOP_MODULE_blPaymentBaseAutoAssignSinglePayment`'s English label** still
  reads "Skip the payment step…", which has been inaccurate since sprint 06's
  correction (the step is not skipped; its block is replaced). Left alone as out
  of scope — it is sprint 06's text — but it should be corrected.
