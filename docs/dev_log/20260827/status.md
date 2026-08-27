# 2026-08-27 — payment-base

**Implementation report: [reports/01-sprint-07-implementation-report.md](reports/01-sprint-07-implementation-report.md)**
— sprint 07 shipped, the premise it had to correct, and what it uncovered in the E2E suite.

## Sprints

- **Sprint 07** — [Single active shipping method: auto-assign and hide the shipping blocks](done/sprint-07-single-active-shipping-method.md) — *S1–S5 + S7 implemented; S6 not built (gated on D1)*
  Binding requirements: [`sprints/_engeneering_requirements.md`](sprints/_engeneering_requirements.md)
  Predecessor: [Sprint 06](../20260826/sprints/sprint-06-single-active-payment-auto-assign.md) (payment half, shipped 2026-08-26)
  Branches: `payment-base` and `stripe` both on `sprint-07-single-active-shipping`; E2E submodule on `projects/Stripe`. **Not pushed.**

## V1 — answered, and it corrected the sprint

Core **does** persist the chosen delivery set on a plain render: `Basket::setShipping()`
mirrors the id into the session, and `getPaymentList()` calls it during `parent::render()`.
The sprint's §1a said the opposite and was wrong; sprint 06's code comment was right.
`isValidPayment()` would refuse a falsy ship set (`_iPaymentError = -2`), but that branch
is unreachable from the payment step. **No latent gap.** Consequences — the assigner now
corrects rather than writes, its write includes the `onUpdate()` core does, and the
ordering invariant is real but narrower than claimed — are in report §0.

## Open decisions

- **D1** — skip the payment step entirely when *both* payment and shipping are auto-assigned.
  **Not built; needs a yes.** Everything it depends on is in place and green, the renderer
  test shows the step is down to a bare form plus navigation, and the E2E run reached that
  exact state on a real shop with the order page still placeable. Recommendation unchanged:
  do it, as its own commit.
- **D2** — deduplicate `SinglePayment*` / `SingleShipping*`. **Answered: no.** The
  implementation strengthened the case — shipping has three rules to payment's six, no
  user parameter, and an idempotence guard payment does not want.
- **Order-overview completeness (BGB §312j)** — **still open, and the only thing blocking
  a clean DoD.** Recommendation: static non-clickable carrier line on the order page,
  full hide on the payment step; settle sprint 06's identical question for the payment
  line at the same time. One Twig condition in two files.

## State

- payment-base gates green: phpcs, phpstan **level max**, phpmd. Neither baseline grew.
- Unit 1182 → **1236**, Integration 95 → **104**, Integration-renderer 7 → **16**.
- stripe Integration 92 → **96**. Its Unit suite still cannot be built in this shop
  (pre-existing class-chain recursion, names no sprint-07 symbol).
- Consumers unchanged: mollie 582, paypal 449, opalreturns 353, OPC 316 (same one
  pre-existing error) — identical counts before and after.
- Verified against the running local shop: with one effective delivery set the selector
  is hidden, with two it is shown, and the kill switch restores the old behaviour both
  ways. The four delivery sets were restored to their recorded pre-test state.
- **E2E green**, all four cells, against `https://daniil.oxiddev.de` (this shop,
  tunnelled — the container's own `getShopUrl()` confirms it):

  | delivery sets | setting | selector (options) | `#orderShipping` | `#orderPayment` |
  |---|---|---|---|---|
  | 2 effective | on | 1 (2) | 1 | 0 |
  | 1 effective | on | **0** | **0** | 0 |
  | 1 effective | off | 1 (1) | 1 | 0 |
  | 1 effective, payment matrix | on | 0 | — | 0 |

  No messages displayed in any cell; the order page stayed placeable throughout.
  Row 2 is also the D1 state (both blocks hidden). Was blocked earlier in the day when
  `sShopURL` still read `pay1.oxid.dev`; the env was repointed and the run went ahead.
  Gotcha for the next run: the specs' default product is a variant parent whose
  `#toBasket` never appears — pass `TEST_PRODUCT_ANID=0757c381b5c2efea14b10d34822c67ed`.
- **Found on the way**: `single-payment-matrix.spec.ts` asserted the shipping selector
  and `#orderShipping` were unconditionally present. Sprint 07 makes that false on a
  one-carrier shop; both assertions are now `EXPECT_SHIPPING`-aware, defaulting to the
  old behaviour. Confirmed necessary, not precautionary — the re-run measured
  `shipSetSelects=0` exactly where the old assertion demanded 1.
- Delivery sets were restored to their exact captured pre-test state. Note that state
  was already `294c2e89…=0` when the E2E run began, having changed since the earlier
  probe in the same session — worth confirming the shop's sets are as intended before
  drawing conclusions from a future run.
- Still inaccurate since sprint 06 and left alone as out of scope: the English label for
  `blPaymentBaseAutoAssignSinglePayment` says "Skip the payment step", which the step
  no longer does.
