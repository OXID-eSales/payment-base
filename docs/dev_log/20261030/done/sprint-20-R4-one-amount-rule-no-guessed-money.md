# Sprint 20 — R4 (payment-base): one amount rule for both commit legs, no guessed contract money

**Branch:** `b-7.4.x-VR-20-return-leg` (from `b-7.4.x` @ `d062955`) · **Date:** 2026-11-03
**Commits:** `697f171` (S3), `eb91725` (S2) · **Status:** done on the branch, not pushed, awaiting merge approval.

## S3 — `ContractService::extractTotals()`

- No price ⇒ `RuntimeException`, nothing saved; currency via `ShopCurrency::nameOf()` (refuses to assume one). The
  former `'EUR'` / `0.0` defaults are gone.
- Red → green: `ContractServiceTest::testCreateContract_TakesAmountAndCurrencyFromTheBasket` (CHF),
  `…WithoutABasketCurrency_RefusesToGuessOne`, `…WithoutABasketPrice_RefusesAZeroContract`. Shared fixture
  `tests/Unit/Fixture/PricedBasket` replaces the `stdClass` baskets that only passed because of the defaults
  (`ContractServiceTest`, `ContractCreationHandlerTest`).
- Not done: refusing a 0.00 total — a fully discounted basket may be a legitimate order (owner decision).

## S2 — the return leg checks the confirmed amount

- `Service\Commit\ConfirmedAmount::matches()` — the rule moved out of `ContractCommitService`, now used by it **and**
  by `CheckoutReturnResponder::respond()`. Mismatch ⇒ nothing dispatched, contract stays open, error logged with both
  figures, `respond()` returns `null`. `alreadyProcessed` resolutions (no amount) are not checked.
- Red → green: `ConfirmedAmountTest` (4), `CheckoutReturnResponderTest::testAConfirmationThatDoesNotMatchTheContractCommitsNothing`
  (one cent short / another currency), `…testAnAlreadyProcessedReturnCarriesNoAmountAndIsNotChecked`; existing
  responder tests now use a 42.00 EUR contract.
- Consumers: only Stripe's `StripeOrderControllerSecurityTest` / `…AgbConsentTest` build a real responder — 1.00 EUR
  contract and 1.00 EUR resolution, unaffected. Mollie/PayPal resolvers still echo the contract amount (check proves
  nothing for them yet → sprint 20 R6/R7).

## Not done in this branch (needs provider changes)

R3 `ReturnAccessGuard` + shared `ContractReturnTokenService` (migration for its own secret, token expiry, legacy-token
switch) — lands with R5–R7 in the provider repos. R1 (PayPal SEC-1 hotfix) and R2 (Stripe PE path) are provider work.

## Gates

Unit 1986 OK (9 skipped) · phpstan no errors · phpcs clean · phpmd clean · integration full suite from the branch:
234 tests, failures = the 7 local `b-7.4.x` baseline failures.
