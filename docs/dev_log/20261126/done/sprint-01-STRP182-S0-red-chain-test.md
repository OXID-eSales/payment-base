# Sprint 01 STRP-182 — S0: the Mythos result as a red unit chain test

**Status:** DONE 2026-11-26

## What changed

`tests/Unit/Controller/CheckoutReturnResponderTest.php`: two tests with the real `EventDispatcher`, the real
`PaymentAuthorizedEventHandler` and a real `PaymentContract` (helpers `realContract()`, `resolverConfirming()`,
`responderWithTheRealHandler()`).

| Test | Mythos step | Result on 3f417ef |
|---|---|---|
| `testRespond_WhenTheContractIsADraftWithTheRealHandler_ReturnsNullAndLeavesTheContractADraft` | step 2 | **red**: `DomainException: Can only transition to PENDING from NOT_FINISHED state` at `PaymentContract.php:123` ← `PaymentAuthorizedEventHandler.php:74` ← `CheckoutReturnResponder.php:109` |
| `testRespond_WhenTheContractIsPendingWithTheRealHandler_MovesItToReadyToCommit` | step 1 (control) | green |

The stack is the same as in the Mythos run. Thus the finding is reproduced without `audit_fakepay`.
