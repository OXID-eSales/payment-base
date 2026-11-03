# Sprint 21 — F7 (payment-base): a refund intent cannot be retargeted, overshoot, or lose decimals

**Branch:** `b-7.4.x-VR-21-refund-booking` (from `b-7.4.x` @ `d062955`) · **Date:** 2026-11-03 · **Commit:** `7bc06c1`
**Status:** done on the branch, not pushed, awaiting merge approval.

## Change (`EventSystem/Handler/RefundIntentHandler`)

- Context = `array_merge($correlation, $base)`: correlation keys travel, base keys (`providerName`, `contractId`,
  `orderId`, `amount`) win.
- Captured branch: refused (logged, nothing dispatched) when the amount exceeds `getRemainingRefundableAmount()`;
  unchanged when no capture amount is recorded (null) — the provider stays the bound there.
- Partial-capture kept amount: `round($x, MinorUnitConverter::decimalsFor($currency))` instead of `round($x, 2)`.

## Red → green

`RefundIntentHandlerTest::testCorrelationKeysCannotReplaceTheBaseKeys`,
`…testACapturedRefundAboveTheRemainingRefundableIsRefused`, `…testACapturedRefundWithinTheRemainingRefundableIsDispatched`,
`…testThePartialCaptureKeepsTheCurrencysOwnDecimals` (KWD 10.000 − 3.333 = 6.667). 3 red before, green after.

## Gates

Unit 1980 OK (9 skipped) · phpstan / phpcs / phpmd clean · payment-base integration from the branch = the 7 local
baseline failures · **opalreturns** unit suite (353 tests) green with this branch's payment-base classes on top.

## Not done (next)

- **F3 `ProviderRefundLedger`** — needs a unique key `(OXCONTRACTID, OXTYPE, OXTRANSACTIONID)` on
  `oe_payments_transaction`. Shops may already hold duplicate refund rows from the double-booking bugs (PayPal SEC-3,
  Stripe S-4), so the migration must first find them; how to treat them (merge, flag, refuse to migrate) is an owner
  decision. **Sized on pay1 (read-only, 2026-11-03): no duplicate `(contract, type, provider id)` groups** in
  `oe_payments_transaction`; 280 Mollie rows and 1 Stripe row carry no provider id (all NULL, none empty - NULLs are allowed repeatedly by
  a MySQL unique key). The unique key migrates cleanly there; the migration still refuses and names duplicates if a
  shop has them (like `OneOrderOneContractMigration`).
- F1/F2 hotfixes (Mollie idempotency key, restock on partial refund) live in the provider repos.
