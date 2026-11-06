# Sprint 27 — RC: OXID totals win — the per-line VAT override and its checkbox leave payment-base

**Date:** 2026-11-06 (planned) · **Epic:** RC (rounding correction) · **Status:** PLANNED — awaiting owner approval
**Repos:** payment-base only (see *Why only payment-base* below)
**Branch:** `b-7.4.x-rounding-correction` (created from `b-7.4.x` e35a849, v1.7.0)
**Requirements:** [`../../20261030/sprints/_engeneering_requirements.md`](../../20261030/sprints/_engeneering_requirements.md)
— TDD (red first), SOLID, DRY, Clean Code, no overengineering, dead-code gate green, no fallbacks.
**Source:** owner policy statement (2026-11-06) and a read of the old PayPal module
(`strpwt7-nov26/oxid-parts/paypal-module`, `PayPalAmountValidator`, `PayPalPurchaseUnitsFactory`, `Model/Basket`).

## The policy this sprint enforces

> The "OXID way" always takes precedence — basket, OPC, checkout, admin. Every payment method receives at least
> the basket total the customer saw. Providers also get the individual line items, calculated per line,
> "approximately" accurate; a few cents of difference against the real total are offset by a *rounding
> adjustment* item or discount. The OXID customer never sees that manipulated list; it exists only in the
> provider's backend.

Two invariants follow from it:

| # | Invariant | Where it lives |
|---|---|---|
| I1 | **payment-base never changes an OXID total.** `oxBasket`, `oxPriceList`, `oxOrder` compute what they compute; the module reads, it does not rewrite. | this sprint: the `PriceList` extension and its setting are deleted |
| I2 | **Reconciliation happens on the provider payload, not in the shop.** Per-line items are built from OXID's unit prices; the residual against OXID's total is absorbed on the wire (adjustment line / discount / total-only fallback). | already true in all three providers (see table below); nothing to add |

## Problem

| # | Finding | Where (b-7.4.x e35a849) | Why it matters |
|---|---|---|---|
| 1 | `PriceList::getVatInfo()` is overridden: when `blPaymentBasePerLineVat` is on it replaces OXID's sum-then-round VAT with round-each-line-then-sum. | `metadata.php:48` (`oxPriceList => PriceList`), `src/Eshop/Core/PriceList.php`, `src/Eshop/Core/PriceToTaxableLineMapper.php` | Breaks I1. The shop's own VAT total, basket total, order total and invoice move by up to a cent per rate — for **every** order, also invoice/bank-transfer ones — so that a provider payload lines up. That is the shop bending to the provider, the opposite of the policy. |
| 2 | The admin checkbox *"Calculate VAT per line item (round each line before summing)"* asks the merchant to make a provider-side rounding decision. | `metadata.php:94` (group `per_line_vat`), `views/admin_twig/{en,de}/payment_admin_lang.php:27,37` | A merchant cannot answer it (it depends on which PSP rounds how), and whichever way it is set, the provider modules already cope (table below). It is a flag with no second value in use → forbidden by *no overengineering*. |
| 3 | `PriceList::isPerLineEnabled()` swallows every `Throwable` and answers `false`. | `src/Eshop/Core/PriceList.php:29-38` | A silent fallback (VR rule: no fallbacks). Goes away with the class. |
| 4 | Domain code reaches into the container (`ContainerFactory::getInstance()->getContainer()->get(...)`) twice. | `src/Eshop/Core/PriceList.php:25,33`; `services.yaml:977` marks the calculator `public: true` only for that | DIP violation that exists only to serve #1. Goes away with the class. |
| 5 | Three stubs exist only so #1 can be unit-tested / analysed: `PriceList_parent`, `Price`, `ModuleSettingServiceInterface::getBoolean`. | `tests/bootstrap-unit.php:174-200`, `tests/PhpStan/phpstan-bootstrap.php:313-370` | Test-infrastructure weight carried for a class that violates the policy. |

### What the providers already do (I2 is satisfied — verified 2026-11-06)

| Provider | Per-line payload | Residual against OXID's total |
|---|---|---|
| **old PayPal module** (reference) | `PayPalPurchaseUnitsFactory::mapItems` from `BasketItem::getUnitPrice()`; `item_total`/`tax_total` summed from the items | `PayPalAmountValidator::validateAndAdjustOrder`: lines lower than OXID → adds a `Rounding Adjustment` item (qty 1, tax 0); lines higher → raises `shipping_discount`. OXID's amount is never touched. `Basket::getPayPalCheckoutRoundDiff()` only *reports* the difference. |
| **paypal** (b-7.4.x) | `Basket/PurchaseUnitBuilder::build` from `BasketLineInput` | `reconcile()`: residual ≤ ceiling → folded into `handling` (+) or `discount` (−); larger → `null` → total-only purchase unit. |
| **mollie-payment** | `Service/MollieLinesBuilder` | appends a `Rounding adjustment` line up to an absolute + relative ceiling; beyond that throws (lines incomplete), never guesses. |
| **stripe** | `Service/CheckoutSessionService::buildLineItems` | itemised sum ≠ `totalGross` (or discounts present) → one `Order Total` line. |

Conclusion: no provider needs the shop's VAT to be rounded per line. The override has no consumer that benefits
from it and one invariant it breaks. **Delete it; add nothing.**

## Design

Deletion only. No new interface, no tagged policy, no resolver, no "mode". The earlier idea of letting each
provider module switch the shop-wide mode on was dropped the moment the policy was written down: the shop
has exactly one rounding, OXID's.

| Piece | After this sprint |
|---|---|
| `Eshop\Core\PriceList` | **deleted**, with its `extend` entry in `metadata.php`. OXID's `PriceList::getVatInfo()` is the only implementation. |
| `Eshop\Core\PriceToTaxableLineMapper` | **deleted** — its only caller was the override; a `Price → TaxableLine` bridge for a consumer that does not exist is YAGNI. `git` keeps it. |
| setting `blPaymentBasePerLineVat` + group `per_line_vat` | **deleted** from `metadata.php` and both admin language files (`SHOP_MODULE_GROUP_per_line_vat`, `SHOP_MODULE_blPaymentBasePerLineVat`). No migration: the module-configuration install drops settings that metadata no longer declares; a stale `oxconfig` row is inert. |
| `Math\Vat\PerLineVatCalculator`, `…Interface`, `TaxableLine`, `VatBreakdown` | **kept** (owner decision: the math stays in payment-base as the shared per-line VAT primitive for provider line builders). `services.yaml` definition stays, `public: true` goes (no container pull left). See **D1** for the dead-code gate. |
| `tests/bootstrap-unit.php`, `tests/PhpStan/phpstan-bootstrap.php` | the three stubs from Problem #5 **deleted**. `ModuleSettingServiceInterface` stub keeps `getString`/`getInteger` (other consumers, Sprint 15/S5) and drops `getBoolean` only if nothing else calls it — verify with grep, do not guess. |
| `docs/engineering/money-arithmetic.md` | `PerLineVatCalculator` section rewritten: it is a *provider-payload* tool, it must never feed an OXID total (I1); the BCMath paragraph keeps its reference. Add the two invariants to `docs/invariants/` as `oxid-totals-win.md`. |
| `CHANGELOG.md` | `## [v1.8.0]` — *Removed (RC-27)*: the `PriceList` extension, the setting, the mapper. Behaviour change for shops that had the box ticked: VAT totals return to OXID core rounding. |
| `metadata.php` version | `1.8.0`. Providers require `^v1.7` → satisfied, no provider pin change. |

## Why only payment-base

`grep -rn "PerLineVat\|blPaymentBasePerLineVat\|TaxableLine\|VatBreakdown" stripe/src mollie-payment/src paypal/src` → no hits.
The providers never read the setting, never called the calculator, and already reconcile on the wire (I2).
Nothing in them changes; their CI keeps passing against payment-base `^v1.7` once 1.8.0 is tagged.
No `b-7.4.x-rounding-correction` branch is created in stripe, mollie-payment or paypal for this sprint.
(The optional follow-up in *Out of scope* would touch all three; it is a separate sprint.)

## Stories (TDD: red first, then the smallest green, then refactor)

| # | Story | Red (first) | Green | Proof |
|---|---|---|---|---|
| S1 | **The shop is not extended.** | `tests/Unit/Metadata/MetadataExtendTest::testExtend_PriceList_IsNotListed` — loads `metadata.php`, asserts `OxidEsales\Eshop\Core\PriceList` is not a key of `$aModule['extend']`. Red because it is. | remove the `extend` row and both `use` lines; delete `src/Eshop/Core/PriceList.php` and `PriceToTaxableLineMapper.php`; delete `tests/Unit/Eshop/Core/PriceListOverrideTest.php`, `…/PriceToTaxableLineMapperTest.php`, `tests/Integration/Math/PerLineVatPriceListTest.php`. | Unit suite green; the new test green; `composer dead-code` reports nothing new (see D1). |
| S2 | **The checkbox is gone.** | `tests/Unit/Metadata/MetadataSettingsTest::testSettings_PerLineVat_IsNotDeclared` — asserts no setting named `blPaymentBasePerLineVat` and no group `per_line_vat`. Red. | delete the setting row; delete the two language keys in `en` and `de`. | `SettingsTranslationsTest` (existing, generic) still green — it now proves every *remaining* group/label is translated; `composer dead-assets` green (no orphan language key). |
| S3 | **Test infrastructure carries no weight for deleted code.** | none — pure removal, covered by the suites running at all | delete the `PriceList_parent` stub (unit + PHPStan bootstrap), the `Price` stub (unit bootstrap; keep only if another unit test needs it — grep first), the `getBoolean` line of the `ModuleSettingServiceInterface` stub if unreferenced. `services.yaml`: drop `public: true` on the calculator, rewrite the comment (no longer "so PriceList can resolve it"). | `composer phpstan` green with **no new baseline entry**; `./bin/pre-commit-check.sh` green. |
| S4 | **The invariant is written down and the math is documented as provider-side.** | none (docs) | `docs/invariants/oxid-totals-win.md` (I1 + I2, the old-PayPal reference, the provider table above); `money-arithmetic.md` §`PerLineVatCalculator` rewritten; `CHANGELOG.md` v1.8.0; `metadata.php` 1.8.0. | reviewer reads the invariant and finds no code path in `src/` that writes into an OXID price object (`grep -rn "setPrice\|setVat\|_aList" src/` → only reads). |
| S5 | **Proof on a real basket.** | — | — | Integration run (`--full`): a 3-line basket with 19 % / 7 % lines at 10.005-style prices; `oxBasket::getProductVats()` equals the value from a vanilla shop with payment-base deactivated (same DB fixture, two runs); numbers in `reports/rc-27-basket-vat-proof.md`. Then one OPC checkout per provider (sandbox) to show the reconciliation still lands: PayPal purchase unit, Mollie lines, Stripe line items — order ids in the report. |

Order: S1 → S2 → S3 (one commit each, gate green before every commit), S4, S5. Status, counts and commit ids
go into `../done/sprint-27-RC.md` when finished.

## Engineering-requirement check (per row of the requirements file)

| Principle | How this sprint honours it |
|---|---|
| TDD-first | S1 and S2 start with a red metadata test; the deletion is the green. No test is deleted without the behaviour it guarded being deleted in the same commit. |
| SRP / OCP / LSP / ISP | Nothing new is designed, so nothing can violate them; the one class that mixed OXID-override, container pulling, settings reading and a try/catch fallback is removed. |
| DIP | The two `ContainerFactory` pulls in domain code disappear; the calculator loses `public: true`. |
| DRY | One rounding in the shop (OXID's). Reconciliation stays in each provider's own payload builder — three implementations exist today; unifying them is *Out of scope*, deliberately, because they differ in what their provider accepts (PayPal: handling/discount; Mollie: a line; Stripe: no negative lines → total-only). |
| Clean Code | Only deletions and a documented invariant. New tests named `testMethod_Condition_ExpectedOutcome`. |
| No overengineering | No policy interface, no tagged iterator, no resolver, no feature flag. The "which provider needs it" question is answered: none. |
| No fallbacks | The `catch (Throwable) → false` in `isPerLineEnabled()` is gone with the class. |
| Dead-code gate | See D1 — the only open point. |
| Pre-commit guard | `./bin/pre-commit-check.sh` before each of the three commits; `--full` before *done*. |
| Proof | S5 report with fixture, both VAT arrays, provider order ids. |
| Git flow | `b-7.4.x-rounding-correction` in payment-base, from `b-7.4.x`; no-ff merge by the owner; no PR, no worktree. |

## Out of scope (the "not now" list)

- **A shared `ItemisationReconciler` in payment-base** (lines + expected total + ceiling → adjustment line or discount, the way the old `PayPalAmountValidator` did it) so PayPal, Mollie and Stripe share one residual rule. Real DRY gain, but it changes three provider payloads and needs three sandbox proofs — its own sprint, after this one, if the owner wants it. It would be the first production consumer of `PerLineVatCalculator`.
- Using `PerLineVatCalculator` inside any provider builder today. None asks for it; adding a call to justify keeping the class is overengineering in reverse.
- Any new admin setting. The merchant has nothing to decide here.
- Removing the `VatBreakdown` accessors from `dead-code-baseline-kept.neon` — they are part of the kept math API (see D1).

## Decisions (owner, before S1 starts)

| # | Question | My proposal |
|---|---|---|
| D1 | **Dead-code gate vs. "keep the math".** After S1, `PerLineVatCalculatorInterface::calculate`, `PerLineVatCalculator`, `TaxableLine` and the `VatBreakdown` constructor have no production caller. The sprint-17 rule says a finding is fixed by deleting, never by growing a baseline. The owner asked to keep the math. Options: **(a)** keep the four classes and add them to `tests/PhpStan/dead-code-baseline-kept.neon` next to the existing `VatBreakdown` entries, with the reason "provider-side per-line VAT primitive; first consumer = reconciler sprint"; **(b)** delete them too (git keeps them; restore in the reconciler sprint when a caller exists). | **(a)**, because the owner said so and the `kept` baseline is exactly the file for a deliberately retained API; but I name (b) as the choice that is cleaner under the sprint-17 rule. |
| D2 | Version: `1.8.0` (Removed, behaviour change for shops with the box ticked) or `1.7.1`? | **1.8.0**. A shop that had per-line on sees its VAT totals change; that is not a patch. Providers' `^v1.7` still matches. |
| D3 | S5 sandbox proof for all three providers, or only PayPal (the one that motivated the policy)? | **All three**, one order each; the reconciliation code is unchanged, so this is a regression check, cheap and final. |
| D4 | Delete the old `PerLineVatCalculatorBigNumberTest` characterisation cases that only exist to document the "diverges from OXID" trade-off? | **Keep**. They pin the calculator's contract, which is still the kept API; they do not touch OXID. |

## Files touched (payment-base)

```
metadata.php                                   extend row, setting row, version
services.yaml                                  calculator: public flag + comment
src/Eshop/Core/PriceList.php                   delete
src/Eshop/Core/PriceToTaxableLineMapper.php    delete
views/admin_twig/en/payment_admin_lang.php     2 keys
views/admin_twig/de/payment_admin_lang.php     2 keys
tests/Unit/Metadata/MetadataExtendTest.php     new (S1 red)
tests/Unit/Metadata/MetadataSettingsTest.php   new (S2 red)
tests/Unit/Metadata/SettingsTranslationsTest.php  docblock: drop the STRP-157 sentence
tests/Unit/Eshop/Core/PriceListOverrideTest.php           delete
tests/Unit/Eshop/Core/PriceToTaxableLineMapperTest.php    delete
tests/Integration/Math/PerLineVatPriceListTest.php        delete
tests/bootstrap-unit.php                        stubs out
tests/PhpStan/phpstan-bootstrap.php             stubs out
tests/PhpStan/dead-code-baseline-kept.neon      D1(a) entries
docs/invariants/oxid-totals-win.md              new
docs/engineering/money-arithmetic.md            §PerLineVatCalculator
CHANGELOG.md                                    v1.8.0
```

## Deploy note

Shops that had *Calculate VAT per line item* ticked return to OXID core VAT rounding with 1.8.0; new orders may
differ by a cent per VAT rate from orders placed while it was on. Existing orders are not touched. Mention in
the release notes; no data migration.
