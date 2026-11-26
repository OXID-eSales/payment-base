# Sprint 01 STRP-182 — S1: the handler refuses a draft explicitly

**Status:** DONE 2026-11-26

## What changed

`src/EventSystem/Handler/PaymentAuthorizedEventHandler.php`

- Removed: the dead branch `if draft → transitionToPending()` (old :71-75). It could only throw
  "Can only transition to PENDING from NOT_FINISHED state".
- Added: `refuseADraft()`, called immediately after the `$contract === null` check, before the handler writes the
  context. It throws `DomainException('Payment authorization refused: contract <id> is a draft and has no order')`.
- Class doc step 1 changed accordingly.

## Red → green

| Test | Before | After |
|---|---|---|
| `PaymentAuthorizedEventHandlerTest::testHandle_WhenTheContractIsADraft_ThrowsWithoutSavingTheContract` | red (message "Can only transition to PENDING …") | green |
| `PaymentAuthorizedEventHandlerTest` (all) | 5 green | 6 green, 15 assertions |
| S0 draft chain test | red (old message) | red (new, clear message) — S2 makes it green |
