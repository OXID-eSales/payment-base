# Sprint 12 — MOL-17: refund after a paid payment — no lost update (payment-base half)

**Date:** 2026-09-24 · **Branch:** `b-7.4.x-MOL-17-refund-after-paid-lost-update` · **Status:** DONE, pushed, not merged.
Twin plan and full report in mollie-payment: `docs/dev_day_log/20260924/{sprints,reports}/MOL-17-refund-after-paid-lost-update.md`.

## What changed here

1. `28b84bc` **Optimistic concurrency on the contract row.** `OXVERSION` (migration `Version20260924120000`,
   registers the `enum` type mapping like its siblings), `DoctrineContractRepository::save()` updates
   `WHERE OXID AND OXVERSION = loaded` and moves the version up; 0 rows on an existing id → `StaleContractException`;
   new rows insert at 0. Version travels via `toArray()['version']` (no public getter: PHPMD public-count).
   Red proof `tests/Integration/Repository/ContractLostUpdateTest` (webhook fulfils, stale return-leg copy
   commits → `committed` before, `fulfilled` after).
2. `fede907` **`CheckoutReturnResponder` yields to a newer state.** On `StaleContractException` from the chain it
   reloads: committed / fulfilled → report that order, write `sess_challenge`, no re-dispatch; still open →
   chain once more on the fresh copy; second refusal → null (webhook completes). Repository is an optional,
   autowired constructor dependency.
3. `330fcb4` **`ContractStateQueryInterface::findByStateAndProvider()`** (one method, own interface), served by
   the repository, aliased to the same instance — the sweep `mollie:reconcile-paid` runs on.

## Gates (final)

Unit 1376 green (4 skipped, pre-existing) · Integration 133 (1 skipped) incl. the three lost-update tests ·
PHPCS clean · PHPMD exit 0 · PHPStan: the 20 findings that exist on `b-7.4.x` HEAD when analysed in the
shop container, none new.

## Consumers

mollie-payment `4a847bb` (webhook retry + captured amount), `44481db` (reconcile command). Stripe / PayPal
inherit 1 and 2 through the shared repository and responder; their webhook handlers may add the retry.

## Operations note

Deploy = run the payment-base migration. On a live shop with an open transaction on `oe_payments_contract`
the `ALTER TABLE` waits for the metadata lock (seen locally behind interrupted test runs).
