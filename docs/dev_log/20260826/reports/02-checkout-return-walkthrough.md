# Checkout walkthrough — "Payment verification failed"

Follow-up to [sprint 06](../sprints/sprint-06-single-active-payment-auto-assign.md);
the findings below are **not** part of that sprint's scope. Shop:
`daniil.oxiddev.de`, one usable payment method (Stripe Wallet), payment-base
iframe mode ON (`blPaymentBaseUseIframe`), OPC active.

## 1. The walkthrough

New spec: `extensions/stripe/tests/e2e/playwright/playwright/tests/checkout/single-active-payment.spec.ts`
— logs in, adds a product, and asserts the sprint's promises on the real shop:

| Step | Assertion | Result |
|---|---|---|
| payment step | no radio, no `payment-option` markup | pass |
| payment step | `form#payment` + hidden `paymentid` survive (the "next" button submits that form by id) | pass |
| payment step | `select[name="sShipSet"]` still there | pass |
| order page | no `#orderPayment` | pass |
| order page | `#orderShipping` still there | pass |
| payment | complete the card form | **not possible here** |

The last row is why the walkthrough cannot go further in this environment: with
`blPaymentBaseUseIframe` on, the order page mounts Stripe Embedded Checkout, and
this shop's Stripe Wallet configuration renders an **express-checkout (wallet)
sheet with no card element** — the frame tree shows
`elements-inner-express-checkout`, no `cardNumber`. Headless Chromium offers no
Apple/Google Pay, so there is nothing to click. The spec annotates and skips that
step rather than failing on it.

## 2. Fixed: the return path was silent (TDD)

Five different checks in `StripeOrderController::checkoutSuccess()` all ended in
the same sentence — *"Payment verification failed"* — and **logged nothing**.
A report of that error was therefore not diagnosable at all.

Red first, then green:

- `tests/Unit/Stripe/Service/Return/CheckoutReturnInputsResolverTest.php` (11 tests)
- `tests/Unit/Stripe/Service/Return/CheckoutReturnRejectionTest.php` (3 tests)
- `tests/Unit/Stripe/Controller/ControllerRequestHelperReturnLoggingTest.php` (3 tests)

Then the implementation:

- `src/Stripe/Service/Return/CheckoutReturnRejection.php` — enum of the six
  reasons; owns both texts, so the customer keeps the vague sentence (telling
  them which check refused would help someone probe the endpoint) while the log
  gets a stable token.
- `src/Stripe/Service/Return/CheckoutReturnInputs.php` — the three validated
  identifiers as one value, so the controller cannot proceed half-validated.
- `src/Stripe/Service/Return/CheckoutReturnInputsResolver.php` — the decision,
  pure and directly testable. Order matters: an unauthenticated token is
  reported as `invalid_contract_token` even when the ids also disagree.
- `ControllerRequestHelper::logReturnRejected()` + a `logger()` seam so the log
  line is assertable without a booted shop. The reason is merged **last** so a
  caller's context cannot overwrite it, and the token is never logged.
- `StripeOrderController` now delegates and funnels all six exits through one
  `rejectReturn()`.

Verified live — six crafted returns, six distinct log lines:

```
STRP: checkout return rejected {"reason":"missing_session_id"}
STRP: checkout return rejected {"reason":"missing_contract_identifiers"}
STRP: checkout return rejected {"reason":"invalid_contract_token"}
STRP: checkout return rejected {"contractId":"deadbeef…","reason":"contract_not_found"}
STRP: checkout return rejected {"contractId":"55333eb8…","checkoutSessionId":"cs_test_bogus","reason":"no_order_created"}
```

The `no_order_created` line now sits directly under the reason Stripe gave
(`Failed to retrieve checkout session … No such checkout.session`), which is the
pairing that was missing.

## 3. Fixed: two container-visibility defects

- `RetryCleanupService` — private but fetched by id, so Symfony inlined it into
  its one consumer and deleted the id. This is what killed checkout with
  *"Payment processing failed"*. Latent; a container recompile made it bite.
- `StripeOrderApiService` — same defect, silent (its caller catches `Throwable`,
  so the admin order tab's Stripe history simply came up empty).
- `CheckoutReturnInputs` / `CheckoutReturnRejection` — **caused by this work**:
  the `src/Stripe/Service/*` autowire sweep tried to instantiate the value object
  as a service and broke container compilation on every page. Both are now in the
  sweep's `exclude` list, next to the other VOs. The lesson is in the module's
  own history: a new file under `Service/` is a service until you say otherwise.

## 4. Root cause: `contract_mismatch` — found and fixed

The next failed attempt named it immediately:

```
[2026-08-26 12:59:46] STRP: checkout return rejected {"reason":"contract_mismatch"}
```

### What was wrong

`checkoutSuccess()` compared the returned contract id against the session
variable `stripe_contract_id` and refused anything else. That pointer is written
in exactly one place — `StripeOrderController::createCheckoutSession()` — and
names only the **last** contract that path created. But a single checkout ends up
with several Stripe checkout sessions, each with its own contract and its own
early order. Measured on one order-page load: **five** stripe contracts, while
the browser made only **one** `createCheckoutSession` call. The others come from
`StripePaymentHandler` (the payment-base/OPC handler path), which creates
sessions without touching the pointer.

Each embedded sheet carries the contract it was opened with. Pay in any sheet but
the last-created one and the return was refused — *after Stripe had charged the
customer*. That is the worst available outcome: money taken, no order, and a
message that says nothing.

### The fix

The contract id in the return is **already authenticated** by its HMAC
`contract_token`, so the pointer comparison was not what protected anything. What
it was reaching for is ownership, so that is now what is checked:
`CheckoutReturnInputsResolver::checkOwnership($contract->getUserId(), $currentUserId)`,
after the contract is loaded. A contract belonging to the shopper who is here may
be completed, whichever path created it; someone else's may not. When either side
is unknown the return proceeds — a charged payment must not be discarded over a
check that cannot be made.

Red first (4 new tests in `CheckoutReturnInputsResolverTest`), then the
implementation, then verified against the live shop:

| Case | Before | After |
|---|---|---|
| forged token | refused | refused (`invalid_contract_token`) |
| unknown contract | refused | refused (`contract_not_found`) |
| **own older contract, session points elsewhere** | **refused (`contract_mismatch`)** | **accepted** — proceeds to the handler chain (`no_order_created`, because the test session id was fake; a real paid session commits) |
| another user's contract | refused | refused (`contract_mismatch`) |

The live check for row 3 reproduced the original scenario exactly: log in, reach
the order page (which creates a fresh contract and repoints the session), then
return with an older contract of the same user.

### Still open (separate defect)

Nothing here stops the **extra checkout sessions** from being created. One
order-page load leaves several Stripe sessions, several contracts and several
early orders, of which the cleanup cancels some. That is wasteful and confusing
(the `oxorder` rows with an empty `OXPAYMENTTYPE` come from it), and it is what
made the mismatch reachable in the first place. Two producers need to agree on
who owns the session in OPC + iframe mode:
`StripeOrderController::createCheckoutSession()` and `StripePaymentHandler`. The
console error that goes with it is still there:

```
[StripeCheckoutFooter] Eager embedded mount failed: IntegrationError: You cannot have multiple Embedded Checkout objects.
```

`tests/checkout/stripe-eager-mount-single-session.spec.ts` guards the browser
side of it (one call per load) and passes; the server-side duplication is
untouched.

## 5. End-to-end confirmation (order 247)

To exercise the return path a payment has to actually complete, which the
wallet-only embedded sheet cannot do headlessly. So `blPaymentBaseUseIframe` was
switched off for one run, the walkthrough driven through the Stripe **hosted**
page with a test card, and the flag **restored to `true`** afterwards (verified
by reading it back).

Result — the whole flow, end to end:

| Checkpoint | Result |
|---|---|
| payment step | no radio, no `payment-option`; form + hidden `paymentid` + `sShipSet` present |
| order page | no `#orderPayment`; `#orderShipping` present |
| Stripe hosted page | reached; offers Card / Klarna / iDEAL / Wero / Bancontact / EPS |
| after payment | `cl=thankyou`, **no error messages on the page** |
| shop log | **no new `checkout return rejected` line** (count unchanged at 9) |
| contract | `committed` |
| order | **247**, `OXTRANSSTATUS=OK`, `OXPAID=2026-08-26 13:18:54`, `OXPAYMENTTYPE=oe_payments_stripe_wallet` |

That is the case that used to end in "Payment verification failed": a checkout
that produced several contracts, returning with one of them.

Two notes from driving it, both fixed in the spec rather than the module: the
hosted page renders a skeleton before the form, so the card fields need waiting
for; and it requires the email before it will submit.

After restoring iframe mode, both specs were re-run against the shop as you have
it configured — `single-active-payment` (payment step annotated wallet-only) and
`stripe-eager-mount-single-session` — **2 passed**.

## 5b. Gates

| Gate | Result |
|---|---|
| stripe `composer phpcs` | clean |
| stripe phpstan `--level=max` | no errors |
| stripe phpmd (changed files, `--strict`) | clean |
| stripe unit (new + touched files) | 39 tests / 74 assertions OK |
| payment-base Unit | 1182 / 2579 OK |
| payment-base Integration | 102 / 314 OK |
| payment-base style | no errors |
| Playwright `single-active-payment` | passes — full payment in redirect mode (§5), annotated skip in iframe mode |
| Playwright `stripe-eager-mount-single-session` | passes |

The stripe unit suite as a whole still cannot be built in this shop (OXID
class-chain recursion under PHPUnit — see report 01 §7), so the tests above were
run per file, which does work.
