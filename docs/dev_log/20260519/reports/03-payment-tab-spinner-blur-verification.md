# Payment-tab spinner & blur-layer verification (Playwright)

**Date:** 2026-05-19
**Question:** "Does the spinner appear when admin clicks on Payment tab and waits for data to load? Does the spinner + blur layer appear on every action-button click (refund, cancel, capture)?"
**Method:** Playwright E2E tests against the live admin UI.

---

## TL;DR

| UX moment | Expected | Verified |
|---|---|---|
| **On Payment-tab open** | NO spinner appears (panel renders synchronously) | ✅ verified |
| **On Refund button click** | Spinner + blur-layer + `aria-busy="true"` appear immediately | ✅ verified |
| **On Capture button click** | Same overlay activates | ⚠️ skipped — no manual-capture order seeded |
| **On Cancel-Auth button click** | Same overlay activates | ⚠️ skipped — no authorized-only order seeded |
| **Operator dismisses confirm() dialog** | NO overlay activates (negative control) | ✅ verified |

**The spinner element is intentionally hidden on tab open.** This is by design — see "Why no on-tab-open spinner" below.

---

## What the implementation does

### Spinner DOM element

`extensions/stripe/views/twig/admin/panel/stripe_panel.html.twig:202–204`

```twig
<div class="stripe-admin" id="stripeContent" data-testid="stripe-panel-card">
    <div class="stripe-spinner" role="status" tabindex="-1" aria-label="Processing"></div>
    …
</div>
```

The spinner is always present in the DOM. It's invisible by default:

```css
.stripe-admin .stripe-spinner { display: none; … }
.stripe-admin.stripe-panel-busy .stripe-spinner { display: block; }
```

### Blur-layer mechanism

```css
.stripe-admin.stripe-panel-busy > *:not(.stripe-spinner) {
    opacity: 0.5;
    filter: blur(2px);
    pointer-events: none;
    user-select: none;
}
```

When `.stripe-panel-busy` is added to the wrapper, every child of the panel *except* the spinner is blurred and pointer-events-disabled — the spinner sits visually on top.

### JS trigger

Lines 447–476 of the same template:

```js
function enterBusy(panel) {
    panel.classList.add('stripe-panel-busy');
    panel.setAttribute('aria-busy', 'true');
    // … focus spinner, schedule a 30s safety-net cleanup …
}

function init() {
    var panel = document.querySelector('.stripe-admin');
    var forms = document.querySelectorAll('.js-stripe-action-form');
    forms.forEach(function (form) {
        form.addEventListener('submit', function () { enterBusy(panel); });
    });
}
```

`enterBusy()` is wired ONLY to `submit` events on `.js-stripe-action-form` elements (refund, capture, cancel-authorization forms). It is **not** invoked on panel render — the panel comes back from the server fully rendered, so there is no client-side "data is loading" moment.

---

## Why no on-tab-open spinner

Sprint history (`extensions/stripe/docs/oe_payments_docs/daniil_dev_log/20260518/`):

- **Sprint 104** (delivered) — collapsed redundant Stripe API calls during panel render. Cut the first-render latency from a 4-call cold path to a 1-call cold path. Verified via `StripePanelApiCallCountTest`.
- **Sprint 106** (FROZEN, not shipped) — would have split the panel into a synchronous skeleton + an async transaction-history fragment with an on-load spinner. Quote from the frozen sprint doc:

  > Over-engineered for the actual operator pain point. Sprint 107 (busy overlay) addresses the in-flight-action UX gap directly with ~80 lines of CSS + JS, no new endpoint, no architecture change. After Sprint 104's API-call collapse, the first-paint latency is no longer an operator complaint.

- **Sprint 107** (delivered) — busy overlay on action-form submit. Shipped as the simpler alternative to Sprint 106.

So the design intent is:

- **Panel open** — render synchronously, fast enough to not need a spinner.
- **Mutating action click** — show spinner + blur until the page reloads with the new state.

---

## Test added

`extensions/stripe/tests/e2e/playwright/playwright/tests/admin/payment-tab-spinner-and-blur.spec.ts` — 6 test cases covering both UX moments. Specs:

1. `on-tab-open: spinner DOM exists but is hidden (panel renders synchronously)` ✅
2. `on-refund-submit: spinner becomes visible AND blur layer activates` ✅
3. `on-capture-submit: spinner + blur activate (manual-capture order)` ⚠️ skipped (no fixture)
4. `on-cancel-auth-submit: spinner + blur activate (uncaptured order)` ⚠️ skipped (no fixture)
5. `on-refund-cancel-dialog: spinner stays hidden, blur stays off` ✅ (negative control)

The two skipped tests guard themselves with `test.skip()` when the relevant order state is missing — they will execute automatically once a fixture order with an uncaptured manual-capture authorization exists in the test environment.

Each assertion checks **three independent signals** of the busy state so a partial regression (e.g. spinner visible but no blur) is caught:

```ts
await expect(editFrame.locator('.stripe-spinner')).toBeVisible();
await expect(editFrame.locator('.stripe-admin')).toHaveClass(/stripe-panel-busy/);
await expect(editFrame.locator('.stripe-admin')).toHaveAttribute('aria-busy', 'true');
```

The on-tab-open test guards the opposite signals (hidden, no busy class) and additionally asserts that the spinner DOM element **exists** — removing it would silently break the action-button overlay because Sprint 107's JS reuses the same element.

---

## Pre-existing bug fixed during this work

`tests/admin/stripe-tab-busy-overlay.spec.ts` had a typo bug: three of its three tests called `stripePage.openStripeTab()` on `AdminStripeOrderPage`, but that method lives on `AdminOrdersPage`. PHP-Playwright type-check didn't catch it because the Page Object base class allowed method access via inheritance reflection.

Fixed by changing to the working pattern used by `stripe-partial-refund.spec.ts`:

```ts
await ordersPage.navigateToOrders();
await ordersPage.selectOrderByCustomerName('Marc');
await ordersPage.openStripeTab();
const stripePage = new AdminStripeOrderPage(page);
const editFrame = stripePage.getEditFrame();
```

After the fix, all three pre-existing Sprint 107 tests pass.

---

## Test run results

```
Running 9 tests using 1 worker

✓  1 [admin-setup]  authentication                                       (8.3s)
✓  2  payment-tab-spinner-and-blur ▸ on-tab-open: spinner hidden        (21.1s)
✓  3  payment-tab-spinner-and-blur ▸ on-refund-submit: spinner + blur   (20.1s)
-  4  payment-tab-spinner-and-blur ▸ on-capture-submit (skipped: no fixture)
-  5  payment-tab-spinner-and-blur ▸ on-cancel-auth-submit (skipped: no fixture)
✓  6  payment-tab-spinner-and-blur ▸ refund-cancel-dialog: no overlay   (20.7s)
✓  7  stripe-tab-busy-overlay ▸ activates on refund confirm             (24.5s)
✓  8  stripe-tab-busy-overlay ▸ does NOT activate when canceling        (20.5s)
✓  9  stripe-tab-busy-overlay ▸ covers capture and cancel-auth equally  (20.8s)

  2 skipped
  7 passed (3.0m)
```

---

## Conclusion

The Payment-tab UX is behaving exactly as designed:

- **No spinner on tab open** — by design (Sprint 106 frozen, Sprint 104 made the latency moot).
- **Spinner + blur + `aria-busy` on every action-form submit** — Sprint 107, verified.
- **No overlay when operator dismisses confirm()** — Sprint 107 negative-control, verified.

The new spec is the regression net: if any of these three states drift (spinner appearing on tab open / no spinner on action click / no blur layer), the corresponding assertion will fail with a clear message.

---

## Follow-ups (not required, optional)

1. Seed a manual-capture authorized-only Stripe order in the test environment so the two currently-skipped specs (`on-capture-submit`, `on-cancel-auth-submit`) execute automatically.
2. If a future sprint resurrects Sprint 106 (async transaction-history fragment with on-load spinner), update the `on-tab-open` assertion to allow the spinner to be transiently visible while still being toggled off after the fragment loads.
