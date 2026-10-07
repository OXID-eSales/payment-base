# Sprint 15 / S4 — ContractCommitService: commit when the provider says paid (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## The problem

`CheckoutReturnResponder` was the only dispatcher of `PaymentAuthorizedEvent` in payment-base, i.e. the only way a
contract went PENDING → COMMITTED was the shopper's browser coming back. Stripe's webhook success handlers *skip*
unless the contract is already committed. A headless client that never returns (closed tab, killed app) leaves a paid
PSP session on a PENDING contract; the provider's stale cleanup later cancels it — money taken, no order.

## What changed

| Piece | Job |
|---|---|
| `Service\Commit\PaymentConfirmation` (readonly DTO) | contract id, provider name, authorization id, provider order id, amount, currency, `requiresCapture`, `source` (`webhook`, `headless_return`, …), `extraContext` |
| `Service\Commit\CommitOutcome` (readonly DTO) | `committed` / `already_processed` / `pending` / `refused` + `orderId` + `reason`; `isSettled()` answers the question a webhook actually asks |
| `Service\Commit\ContractCommitServiceInterface` + `ContractCommitService` | unknown → `contract_not_found`; committed or fulfilled → already processed (no chain); terminal → `contract_<state>` + **error log** (the PSP holds money nothing ships for); amount off by > 0.005 or currency differs (case-insensitive) → `amount_mismatch`; else `PaymentAuthorizedEvent` through the shared chain with the same context keys the responder sets (`providerName`, `contract_id`, `contractId`, `requiresCapture`) plus `commitSource` and the extras; chain leaves it open → `pending`; stale save → reload once, committed = already processed, open = chain once more, twice stale = `stale_contract` |
| `services.yaml` | `EventListenerProviderInterface` + `EventDispatcherInterface` defined in payment-base (identical to every provider's definition, so it boots alone); commit service bound, public |

## Red → green

`Unit\Service\ContractCommitServiceTest` (11) over a scripted dispatcher that plays the handler chain (commit /
pending / stale): contract, unknown refused, committed & fulfilled already processed with order id, cancelled refused
as `contract_cancelled`, amount and currency mismatch refused, cent tolerance and case-insensitive currency accepted,
happy path with event payload and context keys, pending when another condition stays open, stale → committed
elsewhere, stale → chain again on the fresh copy, twice stale left to the other leg.

## Gates

- Unit **1466** green (6 pre-existing skips) · Integration **142** green (1 pre-existing skip)
- phpcs clean · PHPStan level max No errors (DTOs allowed in the Liskov rule like the other value objects) · phpmd clean

## Decisions

- **`CheckoutReturnResponder` is not refactored onto the service.** The sprint doc planned that delegation; the
  responder's return-leg specifics (resolver, `CheckoutReturnCompletedEvent`, `sess_challenge`, MOL-17 settle loop)
  are pinned by their own tests and both paths end in the same handler chain, which is where commit semantics live.
  Folding them together would churn a working return leg for no behaviour change. Revisit when a second
  headless-specific behaviour appears in the responder.
- **Stale-cleanup ordering** (consult the in-flight PSP check before `deleteNotFinishedOrder()`) lives in Stripe's
  `RetryCleanupService::cancelContractAndDeleteOrder()`, not in payment-base — P-Stripe. payment-base's
  `NotFinishedOrderCleanupService` works in days and already leaves settled contracts alone.
- No integration test for the full chain: `PaymentAuthorizedEventHandler` / `ContractCommitmentHandler` are registered
  by the provider modules' services.yaml, so a container-driven test would depend on which provider is active. S6's
  storefront integration covers it with Stripe active.

## For the provider stories

Webhook success handlers replace "`if (!isCommitted()) return`" with
`$commit->commit(new PaymentConfirmation(contractId, 'stripe', $pi, $cs, $amount, $currency, requiresCapture: $manual, source: 'webhook', extraContext: ['checkoutSessionId' => $cs]))`
and treat `isSettled()` as success, `pending` as success-so-far, `refused` as an alert.
