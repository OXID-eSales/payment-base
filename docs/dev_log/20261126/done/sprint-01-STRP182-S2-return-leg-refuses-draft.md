# Sprint 01 STRP-182 — S2: the return leg refuses a draft before the dispatch

**Status:** DONE 2026-11-26

## What changed

`src/Controller/CheckoutReturnResponder.php`

- New private `refusesADraft()`: when the contract is `draft`, it writes one `error` log
  (`providerName`, `contractId`, `authorizationId`, `amount`, `currency`; ids and figures only) and gives `true`.
- `respond()` calls it after the amount check and before the dispatch → `null`, no event, no session challenge.
- `settleOnNewerContract()` calls it on the fresh copy before the second dispatch (a contract reopened for a new
  attempt while the return leg ran).
- The provider controllers (`HandlesCheckoutReturn::dispatchCheckoutReturn()`) and `HeadlessCheckoutService::complete()`
  already treat `null` as failure. No change there.

## Red → green

| Test | Before | After |
|---|---|---|
| `testRespond_WhenTheContractIsADraft_ReturnsNullAndDispatchesNothing` | red (no error log, events dispatched) | green |
| `testRespond_WhenTheFreshContractIsADraft_ReturnsNullAfterOneDispatch` | red (4 events: the draft copy was dispatched again) | green |
| S0 `testRespond_WhenTheContractIsADraftWithTheRealHandler_ReturnsNullAndLeavesTheContractADraft` | red (DomainException) | **green** — Mythos step 2 now ends as `null`, contract `draft` |
| S0 control `…IsPendingWithTheRealHandler_MovesItToReadyToCommit` | green | green |
| `CheckoutReturnResponderTest` (all) | 16 | 20 green, 50 assertions |

`setUp()` gives the shared mock contract `getState() → pending()` (the state those tests mean).
