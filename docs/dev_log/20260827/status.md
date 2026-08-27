# 2026-08-27 — payment-base

## Sprints

- **Sprint 07** — [Single active shipping method: auto-assign and hide the shipping blocks](sprints/sprint-07-single-active-shipping-method.md) — *planned, not started*
  Binding requirements: [`sprints/_engeneering_requirements.md`](sprints/_engeneering_requirements.md)
  Predecessor: [Sprint 06](../20260826/sprints/sprint-06-single-active-payment-auto-assign.md) (payment half, shipped 2026-08-26)

## Open decisions blocking the sprint

- **D1** — skip the payment step entirely when *both* payment and shipping are auto-assigned.
  Recommended yes, as story S6, after S1–S5 are green. Do not build speculatively.
- **D2** — deduplicate `SinglePayment*` / `SingleShipping*`. Recommended no: the payment
  rules have no shipping analogue, and sprint 06 is verified-live code under an
  additive-only requirement.
- **Order-overview completeness** — carried over unresolved from sprint 06 and sharper for
  shipping (carrier + delivery cost are price components in a German order review).
  Recommended: static non-clickable carrier line on the order page, full hide on the
  payment step. Settle it for shipping and payment together.

## Verification item found while planning

- **V1** — core never persists `sShipSet` on a plain render: `getPaymentList()` calls
  `Basket::setShipping()` but writes no session variable, only `changeshipping()` does, and
  neither core's payment form nor sprint 06's reduced form posts `sShipSet`. So
  `validatePayment()` can reach `isValidPayment(..., $shipSetId = false)` today. Pre-existing;
  the sprint's assigner closes it as a side effect. Confirm the live behaviour before and
  after and record it either way.

## State

- Nothing implemented today. Working tree clean at planning time; last commit is sprint 06's.
