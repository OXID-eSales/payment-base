# On-tab-open spinner added to admin Payment tab

**Date:** 2026-05-19
**Trigger:** User request after report 03 verified the on-tab-open spinner was intentionally absent. The team decided the brief load window on Payment-tab click should always show an explicit affordance.
**Approach:** Minimal pragmatic — render the panel wrapper server-side with `.stripe-panel-busy` already applied; clear it on `DOMContentLoaded`. Reuses Sprint 107's existing CSS + spinner DOM element; no new JS framework, no async-fragment architecture (avoiding the over-engineering that froze Sprint 106).

---

## What changed

### `extensions/stripe/views/twig/admin/panel/stripe_panel.html.twig`

**Wrapper element** (line 202) — busy class + `aria-busy` set server-side so the spinner is visible from first paint:

```diff
- <div class="stripe-admin" id="stripeContent" data-testid="stripe-panel-card">
+ <div class="stripe-admin stripe-panel-busy" id="stripeContent" data-testid="stripe-panel-card" aria-busy="true">
```

**Init script** (line ~462) — clears the server-set busy state once the DOM is ready and the rest of the panel has parsed:

```diff
  function init() {
      var panel = document.querySelector('.stripe-admin');
      if (!panel) return;
+     panel.classList.remove('stripe-panel-busy');
+     panel.removeAttribute('aria-busy');
      var forms = document.querySelectorAll('.js-stripe-action-form');
      forms.forEach(function (form) {
          form.addEventListener('submit', function () { enterBusy(panel); });
      });
  }
```

That's the entire production change — **5 lines of diff**.

---

## How it works

1. Admin clicks the Payment tab → browser navigates the list frame to the panel URL.
2. Server processes the request (~30 ms post Sprint 104), streams HTML.
3. First byte reaches the browser. The wrapper element is `<div class="stripe-admin stripe-panel-busy" ... aria-busy="true">` — Sprint 107's CSS (`.stripe-admin.stripe-panel-busy .stripe-spinner { display: block; }`) makes the spinner visible immediately.
4. Browser parses the rest of the body. Blur layer (`opacity: 0.5; filter: blur(2px); pointer-events: none`) covers the content as it lays out.
5. The inline `<script>` at the end of the template fires on `DOMContentLoaded` and runs `init()` — which removes `.stripe-panel-busy` and `aria-busy`. The CSS hides the spinner and unblurs the content.
6. Admin sees the spinner during the parse/paint window (longer for cold blob / large transaction history, near-instant for warm renders), then the panel snaps into focus.

**Same overlay element is reused** by the existing Sprint 107 action-button handler — adding it on form submit re-enters the busy state for the refund/capture/cancel-auth round-trip. Zero new CSS, zero new DOM, zero new JS framework.

---

## Why this approach over Sprint 106's frozen plan

Sprint 106 would have split the panel into a synchronous skeleton + an async XHR fragment with a JS controller and a new admin endpoint. Quoting the freeze note:

> Over-engineered for the actual operator pain point. Sprint 107 (busy overlay) addresses the in-flight-action UX gap directly with ~80 lines of CSS + JS, no new endpoint, no architecture change.

The same logic applies to the on-tab-open spinner. The simplest thing that works is to **pre-paint the busy state server-side** and clear it on DOM ready. No XHR, no endpoint, no JS controller, no Stimulus. The spinner is visible during the actual wait (HTML transfer + parse + paint) and clears the moment the user can interact.

What this approach **does NOT** cover:
- The window between *click* and *first byte of response* (server processing time). Browser's native navigation indicator (URL-bar spinner / tab favicon) covers that window — it's what the operator already perceives as "loading."

What this approach **does** cover:
- The actual perceivable lag on slow renders (cold contract blob, big transaction history).
- A consistent visual affordance — same spinner + blur the operator sees on action submits, now also on tab open.

---

## Tests

`tests/e2e/playwright/playwright/tests/admin/payment-tab-spinner-and-blur.spec.ts` — updated to reflect the new behavior:

| Test | Assertion |
|---|---|
| `on-tab-open: panel settles into idle state after DOM is ready` | After `domcontentloaded`, the busy class is gone and the spinner is hidden. Proves `init()` correctly clears the server-set busy state. |
| `on-tab-open: template renders wrapper with busy class + aria-busy attribute` | Reads `stripe_panel.html.twig` directly and asserts both `class="stripe-admin stripe-panel-busy"` and `aria-busy="true"` are present. **Regression net** — if a future contributor strips the busy class from the template, the spinner stops appearing and this test fails immediately with a clear pointer at the right file. |
| `on-refund-submit: spinner + blur activate` | Unchanged — still proves the action-button overlay works. |
| `on-refund-cancel-dialog: no overlay when dismissing` | Unchanged. |
| `on-capture-submit` / `on-cancel-auth-submit` | Auto-skip when the test environment doesn't have a fixture for those order states. |

### Playwright run

```
10 tests, 1 worker
✓  1 admin-setup ▸ authentication                                               (7.7s)
✓  2 on-tab-open: panel settles into idle state after DOM is ready             (21.1s)
✓  3 on-tab-open: template renders wrapper with busy class + aria-busy          (1ms)
✓  4 on-refund-submit: spinner becomes visible AND blur layer activates        (21.1s)
-  5 on-capture-submit (skipped: no manual-capture fixture)
-  6 on-cancel-auth-submit (skipped: no authorized-only fixture)
✓  7 on-refund-cancel-dialog: spinner stays hidden, blur stays off             (20.5s)
✓  8 Sprint 107 ▸ busy overlay activates on refund confirm                     (24.4s)
✓  9 Sprint 107 ▸ does NOT activate when operator cancels confirm              (21.0s)
✓ 10 Sprint 107 ▸ covers capture and cancel-authorization equally              (20.5s)

8 passed, 2 skipped (3.0 min)
```

### Stripe pre-commit

```
✓ PHPCS / PHPStan / PHPMD / PHPUnit / module-smoke
Status: COMMITABLE
```

The template change is pure presentation (HTML/CSS/JS); no PHP-side change, no test count delta.

---

## Cache-clear notes (after merging)

Twig templates are cached. After deploying this change, the admin needs to:

```bash
docker compose exec php bash -c 'rm -rf source/tmp/*'
docker compose exec php bin/oe-console oe:cache:clear
```

This was done during the change to verify locally. If the spinner doesn't appear on tab open after deploy, suspect a stale Twig cache first.

---

## Possible follow-up (optional)

If the brief flash before navigation (server-thinking window) becomes a complaint, that's where Sprint 106's plan applies — but only with cause. The current implementation handles the *perceivable* wait. Don't bring back the async-fragment architecture without a real operator pain report.

---

## Update (2026-05-19 evening) — inter-order navigation gap closed

### Reported gap

After the initial server-side-busy-class change shipped, the operator reported the spinner **didn't** appear when they were already on the Payment tab and clicked a different order in the list. Trace:

1. Admin on Order A, Payment tab → panel rendered, idle.
2. Admin clicks Order B in the sibling list frame.
3. **Window of no spinner**: server round-trip happens; the OLD panel (Order A) stays visible until the new HTML arrives.
4. New HTML arrives with busy class → spinner visible briefly during parse.
5. `DOMContentLoaded` → busy cleared → panel idle for Order B.

Step 3 is the gap. The server-side busy class only helps once the new HTML reaches the browser. Before that, the OLD panel — which already had its busy class cleared by `init()` — sits inertly while the operator waits.

### Fix — cross-frame click listener

The panel lives in OXID admin's `edit` frame; the order list lives in the sibling `list` frame. From inside `edit`, JS can reach `window.parent.frames['list'].document` (same-origin admin pages). Bind a **capturing** click listener there: on any click that targets an anchor or submit element, immediately call `enterBusy(panel)` on the current panel. The click event fires before the browser starts the navigation, so the busy class is applied while the operator still sees the old panel. When the new HTML arrives (also carrying `.stripe-panel-busy` server-side) the spinner remains visible continuously across the round-trip.

```js
function bindCrossFrameNavSpinner(panel) {
    try {
        var list = window.parent?.frames?.['list'];
        if (!list || !list.document) return;
        list.document.addEventListener('click', function (e) {
            var trigger = e.target?.closest?.('a[href], button[type="submit"], input[type="submit"]');
            if (!trigger) return;
            enterBusy(panel);
        }, true);
    } catch (err) { /* cross-frame blocked or list frame missing — no-op */ }
}
```

Called from `init()` after the initial busy-clear, alongside the form-submit handlers.

### Safety net for non-list-frame navigations

Also added a `pagehide` listener that re-enters busy on the current panel as the page is about to be replaced. Covers:
- URL-bar typed navigation.
- Browser back / forward buttons.
- Any JS-driven `location.href = …` from within the edit frame.

```js
window.addEventListener('pagehide', function () { enterBusy(panel); });
```

### Test added

`payment-tab-spinner-and-blur.spec.ts ▸ on-list-frame-click: current panel re-enters busy state before navigation`:

Synthesises a click on the first `<a href>` in the list frame (with `preventDefault` to keep the assertion race-free) and immediately reads back the panel's class + `aria-busy` state. Asserts both are set. This verifies the cross-frame hook fires and applies the busy state synchronously, before any actual navigation. Test runs in ~20 s and passed on the first run.

### Final Playwright suite

```
8 tests, 1 worker
✓  authentication
✓  on-tab-open: panel settles into idle state after DOM is ready
✓  on-list-frame-click: current panel re-enters busy state before navigation
✓  on-tab-open: template renders wrapper with busy class + aria-busy attribute
✓  on-refund-submit: spinner + blur activate
-  on-capture-submit (skipped: no manual-capture fixture)
-  on-cancel-auth-submit (skipped: no authorized-only fixture)
✓  on-refund-cancel-dialog: spinner stays hidden when canceling
6 passed, 2 skipped (2.3 min)
```

### Coverage summary (final)

| UX moment | Spinner shows? | Mechanism |
|---|---|---|
| Click Payment tab from another tab | ✅ | Server-side `.stripe-panel-busy` on wrapper; cleared on `DOMContentLoaded`. |
| Click action button (refund / capture / cancel-auth) on the current panel | ✅ | Sprint 107 form-submit handler. |
| Click a different order in the list while on Payment tab | ✅ **(new)** | Cross-frame click listener installed on the `list` frame from `edit` frame's `init()`. |
| URL bar navigation / back / forward | ✅ **(new, fallback)** | `pagehide` listener re-enters busy on the outgoing panel. |
| Admin types a new URL into the URL bar of the parent frame | ⚠️ partial — only the panel frame has the spinner; the parent admin chrome doesn't change. Browser-native nav indicator covers this. |
| Operator dismisses confirm() dialog on action submit | ✅ negative-control: spinner stays hidden | Sprint 107. |

Stripe pre-commit `--full` still green. Template change only; no PHP-side change.
