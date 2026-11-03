# Sprints 16–25 — violations review 2026-10-30 (epic VR)

Plan built from the four violations reports and the four dead-code checks in [`../reports/`](../reports/).
Rules for every sprint: [`_engeneering_requirements.md`](_engeneering_requirements.md).
Code state the plan is based on: payment-base `6fc1f0a` (review) / `d062955` (dead-code check; the 5 commits in
between are SEAL-1 sealed client config and touch no story here), stripe `b4ae8b0`, mollie-payment `5f9fc0e`, paypal
`8538946`.

## Order

| # | Sprint | Priority | Repos | Depends on |
|---|---|---|---|---|
| 16 | [VR-OPS — pay1 recovery and deploy hygiene](sprint-16-VR-OPS-pay1-recovery-and-deploy-hygiene.md) | **P0, live** | pb, stripe, deploy | — |
| 17 | [VR-DEAD — dead-code check in each module, removal, CI gate](sprint-17-VR-DEAD-dead-code-removal-and-gate.md) | P1 | all four | 16 |
| 18 | [VR-LOG — logs carry ids, not content](sprint-18-VR-LOG-logs-carry-ids-not-content.md) | P1 | all four | 16 (O5) |
| 19 | [VR-WEBHOOK — one claim, one guard chain](sprint-19-VR-WEBHOOK-one-claim-one-guard-chain.md) | P1 | all four | 17 (DC-PB2), 18 (L2) |
| 20 | [VR-RETURN — one commit path, owned return leg](sprint-20-VR-RETURN-one-commit-path-owned-return-leg.md) | **P0** (R1, R2) / P1 | all four | — (R1/R2 hotfix), 17 for the rest |
| 21 | [VR-REFUND — idempotent refunds, correct restock](sprint-21-VR-REFUND-idempotent-refunds-and-correct-restock.md) | **P0** (F1, F2) / P1 | all four | — (F1/F2 hotfix) |
| 22 | [VR-ADMIN — one admin money path, invariants, policy](sprint-22-VR-ADMIN-one-admin-path-and-money-policy.md) | P1 | all four | 17 (DC-PB5), 21 |
| 23 | [VR-STATE — provider state edge cases](sprint-23-VR-STATE-provider-state-edge-cases.md) | P1 | all four | 18 (L3/L4), 20 (R6), 21, 22 (gateways) |
| 24 | [VR-HARDEN — entry points and secrets](sprint-24-VR-HARDEN-entry-points-and-secrets.md) | P2 | pb, stripe, paypal | 17 (MCP decision), 19 |
| 25 | [VR-DRY — shared pieces, no fallbacks](sprint-25-VR-DRY-shared-pieces-and-no-fallbacks.md) | P2 | all four | 19–23 |

## Hotfixes that should not wait for their sprint

Each is small, self-contained, test-first, and can ship from its own branch straight after sprint 16:

| Hotfix | Story | Why now |
|---|---|---|
| PayPal return-leg IDOR | 20 R1 | another shopper's order can be pushed through and viewed |
| Stripe PI taken from the request | 20 R2 | one paid PaymentIntent can mark other orders paid |
| Mollie idempotency key never sent | 21 F1 | double click = double refund/capture |
| Restock on any refund (Stripe, Mollie) | 21 F2 | every partial refund corrupts stock and order lines |

## Traceability — every High finding

| Report | Finding | Story |
|---|---|---|
| payment-base | S1 claim leaks on `\Error` | 19 W1 |
| payment-base | S2 return leg without amount check | 20 R4 |
| payment-base | S3 `'EUR'`/`0.0` contract fallback | 20 R4 |
| payment-base | D1 fat adapter, dead templates | 17 DC-PB5, 22 A4 |
| payment-base | D4 capture/refund rules ×3 | 22 A3, A5–A7 |
| payment-base | live: migrations missing on pay1 / C4 send-now fallback + PII | 16 O1, O2, O5 |
| stripe | S-1 PI from request | 20 R2 |
| stripe | D-1/D-2 restock on any refund, pinned by test | 21 F2 |
| stripe | logs L-1/L-2 stale endpoints, missing events | 16 O6 |
| stripe | logs L-3 cancel/fail/refund never applied | 18 L4, 23 T3 |
| mollie | S-H1 idempotency key not sent | 21 F1 |
| mollie | S-H2 restock on any refund | 21 F2 |
| mollie | S-H3 `paid` on terminal contract stranded | 23 T1, T2 |
| mollie | D-H1 two commit paths | 20 R6 |
| mollie | T-H1 money rules untested/pinned | 21 F1/F2/F6, 23 T2 |
| paypal | SEC-1 return-leg IDOR | 20 R1 |
| paypal | SEC-2 webhook claim never released | 19 W3 |
| paypal | SEC-3 refunds booked twice | 21 F4 |
| paypal | D-1 webhook pipeline ×3 | 19 W2–W5 |
| paypal | D-2 return check per provider | 20 R3 |
| paypal | T-1 tests cover dead guard | 20 R1 |

Medium findings are listed in each sprint's "Sources" line; Low findings are folded into the stories where they touch
the same code, the rest stay in the reports' section 3.

## Owner decisions collected from the sprints

1. Run the pay1 migration and purge the PII log lines (16 O1/O2).
2. Ship the four hotfixes ahead of their sprints?
3. MCP/ACP: keep (then 24 H8) or delete (17 DC-PB1/DC-PB7a).
4. opalsubscription listens to payment-base events that are never dispatched — fix wiring or delete (separate ticket).
5. PayPal vault, `blPayPalRemoveBy*`, `blStripeRemoveBy*`: implement or remove (admin GraphQL schema change).
6. Stripe disputes and Radar: flag/fix or delete (23 T3/T6, 17 DC-S3).
7. payment-base BC policy for removals: minor + CHANGELOG "Removed", or next major.
8. Optional: `Money` everywhere (25 D7).

## Out of scope for this epic

one-page-checkout log PII and the OPC-289 tracer at ERROR (OPC tickets); opalsubscription fix itself.
