# Sprint 01 — STRP-182: a payment confirmation for a draft contract is refused, not an uncaught exception

**Engineering requirements (binding):** [`dev_log/_engeneering_requirements.md`](../../../_engeneering_requirements.md)
**Date:** 2026-11-26 · **Ticket:** STRP-182 (clone of Mythos 2026.11-123, labels `area-payment-base`, `mantis-todo`) · **Status:** DONE 2026-11-26 — S0–S4 done, D1/D2 as default; see `../sprint-01-STRP182-S*.md`; stripe twin branch (tests only); awaiting owner merges
**Repos:** payment-base only. Providers (stripe, mollie-payment, paypal) and one-page-checkout: no change.
**Branch:** `b-7.4.x-uncaught-exception-STRP-182` from `b-7.4.x` 3f417ef (v1.8.0-rc3). Feature branch only, the owner merges.
**Source:** Mythos finding 2026.11-123 (payment-base v1.7.4, repro `repro-2026.11-123.php`, run 2026-11-19 on `opc-audit`).

## Definition of Done (sprint-level)

1. A payment confirmation for a contract in state `draft` does not throw out of the return leg. The responder
   returns `null` (the failure that each provider controller already shows), logs one `error` with ids only, and
   dispatches no event. The contract stays `draft`. No order is created or paid.
2. The webhook leg (`ContractCommitService`) gives `CommitOutcome::refused('contract_draft')` for the same case.
3. `PaymentAuthorizedEventHandler` has no dead `DRAFT → PENDING` branch. When a draft contract gets to it, the
   handler refuses it with an explicit `DomainException` that names the contract. The contract is not saved.
4. The control case does not change: a `pending` contract (order created before the customer leaves) commits.
5. `./bin/pre-commit-check.sh --full` → `Status: COMMITABLE`. The unit suites of stripe, mollie-payment, paypal and
   one-page-checkout give the same counts against the branch as before.

## Problem

| # | Finding | Where (b-7.4.x 3f417ef) | Why it is important |
|---|---|---|---|
| 1 | The handler calls `transitionToPending()` for a `draft` contract. The contract allows `PENDING` only from `NOT_FINISHED`. Thus the branch always throws `DomainException: Can only transition to PENDING from NOT_FINISHED state`. The rule is in the contract since STRP-78 (262266e), so the branch was dead from the start. | `src/EventSystem/Handler/PaymentAuthorizedEventHandler.php:71-75`, `src/Contract/PaymentContract.php:120-132` | The only effect of the branch is the uncaught exception. |
| 2 | The return responder catches only `StaleContractException` around the dispatch. The `DomainException` goes up to the provider controller → error page, no order. | `src/Controller/CheckoutReturnResponder.php:108-112` (also `settleOnNewerContract()`, :204-214) | The customer has paid and sees an error (Mythos "Actual"). `HeadlessCheckoutService:182` uses the same responder. |
| 3 | The webhook leg refuses `committed`, `fulfilled` and terminal contracts, but not `draft`. A draft goes into the chain and throws the same exception. | `src/Service/Commit/ContractCommitService.php:72-90` | The webhook claim is released and the PSP sends again a delivery that can never succeed. |

How a contract gets an order: `ContractDraftCompletedEvent` → `EarlyOrderCreationHandler` (`draft → not_finished`
with the order id `→ pending`), **before** the customer goes to the PSP. This is the one path. The standard handler
of OPC does the same in `CheckoutService` (first `ContractDraftCompletedEvent`, then `PaymentAuthorizedEvent`). Thus
OPC is not affected. A contract is `draft` at the confirmation only when:

- a provider module did not create the order before the redirect (an integration error of that module), or
- the contract was reopened for a new attempt (`PaymentContract::reopenForNewAttempt()`, :254) and a late
  confirmation of the closed attempt arrives.

## Design

The Mythos ticket allows two answers: "create the order first" or "a handled failure the provider module can show".
This sprint uses the **handled failure**:

- To create the order at the confirmation is a second order-creation path after the payment. This breaks
  *one path, no fallbacks* and *contract-first* (requirements, core table). Also, a headless return has no session
  basket from which to make the order.
- A refusal is what security rule 1 already tells: *"a provider event that does not fit the current state is
  refused, never forced (`payment_authorized` fulfils only from `pending`)"*.

```
return leg   CheckoutReturnResponder::respond()
             resolver → amount check → [NEW] draft? → error log, return null      (no dispatch)
                                     → dispatch CheckoutReturnCompleted + PaymentAuthorized
             settleOnNewerContract(): [NEW] the same draft check on the fresh copy

webhook leg  ContractCommitService::refusal()
             committed/fulfilled → alreadyProcessed; terminal → refused; [NEW] draft → refused('contract_draft')

handler      PaymentAuthorizedEventHandler::handle()
             [REMOVED] if draft → transitionToPending()
             [NEW]     if draft → throw DomainException("… contract X is a draft and has no order …")
```

The handler check is the invariant. Without it, the handler saves a draft without a change and the caller thinks
that the chain ran (a silent skip). The two edge checks are the handled refusals. This is the same pattern as the
amount check, which is also in the two legs (VR-20).

The log line has ids only (security rule 8): contract id, provider name, authorization id, amount, currency. The
level is `error`, because the money is confirmed and no order exists. The merchant must find it (the same reason
as the terminal refusal in `ContractCommitService`).

## How this sprint applies the requirements

| Requirement | Application in this sprint |
|---|---|
| TDD-first | Each story starts with its named red test. S0 shows the Mythos result in a unit chain test before a code change. |
| Tests may not pin a bug | No test pins the draft branch (checked: `grep -i draft` in the three test classes). |
| One path, no fallbacks | No order creation at the return. No `catch (DomainException)` that hides the error: the edges check the state before the dispatch. |
| Contract-first | The state changes only through `EarlyOrderCreationHandler`. No new transition. |
| Agnosticism / additive only | No public API change. No new class (plain `DomainException`). The provider controllers already show `null` as a failure (`HandlesCheckoutReturn::dispatchCheckoutReturn()`). |
| Logs carry ids only | See Design. |
| Proven through the UI | See owner decision D1: no real provider gets to this branch, thus no Playwright click can get to it. |
| Dead-code gate | No new class or method without a production caller. |

## Stories

### S0 — The Mythos result as a red unit chain test

- File: `tests/Unit/Controller/CheckoutReturnResponderTest.php`.
- Test `testRespond_WhenTheContractIsADraftWithTheRealHandler_ReturnsNullAndLeavesTheContractADraft`: a real
  `EventDispatcher` with the real `PaymentAuthorizedEventHandler`, a real `PaymentContract` in `draft` with one
  `payment_authorized` condition, a resolver that gives `readyToCommit` with the contract amount.
- Today: red with `DomainException: Can only transition to PENDING from NOT_FINISHED state` (= Mythos step 2).
- Control test `testRespond_WhenTheContractIsPendingWithTheRealHandler_MovesItToReadyToCommit` (= Mythos step 1):
  green before and after.

### S1 — The handler refuses a draft explicitly

- File: `src/EventSystem/Handler/PaymentAuthorizedEventHandler.php:71-75` and the class doc (:18-21).
- Red test `testHandle_WhenTheContractIsADraft_ThrowsWithoutSavingTheContract` (`PaymentAuthorizedEventHandlerTest`):
  expects a `DomainException` with the contract id and "draft" in the message; `save` never; the state stays `draft`.
- Steps: remove the branch. Add the guard immediately after the `$contract === null` check (before the handler
  writes the context).

### S2 — The return leg refuses a draft before the dispatch

- File: `src/Controller/CheckoutReturnResponder.php:104-114` and `:204`.
- Red tests (`CheckoutReturnResponderTest`):
  - `testRespond_WhenTheContractIsADraft_ReturnsNullAndDispatchesNothing` (no dispatch, one `error` log with
    `contractId`, no session challenge);
  - `testRespond_WhenTheFreshContractIsADraft_ReturnsNullAfterOneDispatch` (the first dispatch is stale, the fresh
    copy is a draft).
- S0 becomes green with this story.

### S3 — The webhook leg refuses a draft

- File: `src/Service/Commit/ContractCommitService.php:72-90`.
- Red test `testCommit_WhenTheContractIsADraft_IsRefusedAndNotDispatched` (`ContractCommitServiceTest`): outcome
  `REFUSED`, reason `contract_draft`, no event.
- Steps: one more branch in `refusal()`, with the `error` log of the terminal case.

### S4 — Proof and record

- `./bin/pre-commit-check.sh --full` → `COMMITABLE`.
- Unit suites of stripe, mollie-payment, paypal, one-page-checkout against the branch: counts before and after in
  the report.
- `status.md`, one `done/` report per story, this plan moved to `done/sprints/`.

## Risks

| Risk | Answer |
|---|---|
| A real provider gets to the draft case and now shows a failure page instead of an exception page. | The customer result is the same (no order), but it is handled and logged with ids, and the webhook stops the retries. Mythos did not measure a real provider. All real providers create the order first (`EarlyOrderCreationHandler`, `PayPalOrderCreationHandler`). |
| The mock `PaymentContractInterface` in `CheckoutReturnResponderTest::setUp()` has no `getState()`. | `setUp()` gives it `ContractState::pending()` (the state that those tests mean). |
| The handler also skips `not_finished` at the confirmation (order created, `pending` not reached) without a message. | Out of scope. This occurs only when `EarlyOrderCreationHandler` fails between its two saves. Follow-up note in the report. |

## Owner decisions (with default)

| # | Question | Default |
|---|---|---|
| D1 | UI proof: no real provider keeps a contract `draft`, and the Mythos test module `audit_fakepay` is not in this checkout. | The unit chain test S0 is the proof. Ask Mythos to run `repro-2026.11-123.php` again on `opc-audit` after the merge (expected: step 2 `responder: null`, contract `draft`, 0 paid orders). |
| D2 | Handled refusal or order creation at the return? | Handled refusal (see Design). |
