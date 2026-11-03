# Status — dev_log 20261030 (violations review → sprints 16–25, epic VR)

Plan: [`sprints/README.md`](sprints/README.md). Reports: [`reports/`](reports/).

| Sprint | Repo | Branch | Status |
|---|---|---|---|
| 16 VR-OPS | payment-base | `b-7.4.x-VR-16-deploy-hygiene` | O3 schema-check script + O5 fail-loud order mails (+ no-contract follow-up): DONE on the branch, awaiting push/CI + merge approval. O1/O2 (pay1 migrate + log purge), O4 (deploy script), O7 (alert): open — need the owner / the deploy repo |
| 16 VR-OPS | stripe | — | O6 endpoint registrar: open |
| 17 VR-DEAD | payment-base | `b-7.4.x-VR-17-dead-code` | DC-PB1..6 DONE on the branch (80 classes, unit 1802 OK, integration = baseline) |
| 19 VR-WEBHOOK | payment-base | `b-7.4.x-VR-19-webhook-claim` | W1 DONE on the branch (Throwable + stale claim re-claim, unit + integration) |
| 18, 20–25 | all | — | PLANNED |

## Facts found while implementing

- 2026-11-03: pay1 is now **4** payment-base migrations behind (`migrations:up-to-date oe_payment_base` → exit 1,
  read-only check). The order mails there still go out before the payment until O1 runs.
- pay1's shop runs a DBAL whose exception message includes the bound parameters
  (`An exception occurred while executing '…' with params [...]`) — the source of the mail bodies in `oxideshop.log`.
- Local integration baseline on `b-7.4.x` (2026-11-03): 7 failures, all GraphQL admin
  (`AdminPaymentOperationsIntegrationTest` ×6, `AdminModuleParityTest` ×1) — environment, not branch-related.
