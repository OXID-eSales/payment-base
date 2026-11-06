# Invariant — OXID's totals win; per-line rounding lives only in the provider payload

**Audience:** anyone building a checkout payload, a line-item list or a refund amount in a payment
provider module that targets `payment-base`, and anyone tempted to extend an OXID price class here.

## The rules

**I1 — payment-base never changes an OXID total.** `oxBasket`, `oxPriceList`, `oxOrder` and `oxPrice`
compute what they compute. payment-base reads those values; it never chains into them, never rewrites
a VAT figure, never rounds differently from OXID core. The `extend` section of `metadata.php` MUST
NOT list `OxidEsales\Eshop\Core\PriceList`, `Price`, `Basket` or `BasketItem`
(guarded by `tests/Unit/Metadata/MetadataExtendTest`).

**I2 — reconciliation happens on the provider payload, not in the shop.** Every payment method
receives at least the basket total the customer saw in the OXID checkout. Where a provider also
takes line items, the module builds them per line from OXID's unit prices ("approximately" exact),
and absorbs the cent residual against OXID's total **in the payload**: a rounding-adjustment item, a
discount, a handling amount, or a total-only fallback — whatever that provider's API accepts. The
OXID customer never sees that list; it exists only in the provider's backend.

## Why these rules exist

Germany's tax rules allow both "sum then round" and "round each line then sum"; OXID core does the
first, most provider APIs expect line items that add up exactly. The tempting shortcut is to make the
shop round per line so the provider payload adds up by itself. That is the shop bending to the
provider: every order (also invoice and bank transfer) moves by up to a cent per VAT rate, invoices
and ERP exports no longer reconcile with what OXID would have printed, and the merchant is asked a
question ("round per line?") only a provider could answer. Sprint 125 (STRP-157) shipped exactly
that shortcut as a `PriceList` override behind a checkbox; Sprint 27 (RC, 2026-11-06) removed it.

The reference implementation is the old PayPal module (`PayPalAmountValidator::validateAndAdjustOrder`):
items are built from `BasketItem::getUnitPrice()`, `item_total`/`tax_total` are summed from the items,
and the difference to OXID's amount becomes a `Rounding Adjustment` item (lines too low) or a
`shipping_discount` (lines too high). OXID's amount is never touched.

## How the current providers honour I2 (verified 2026-11-06)

| Provider | Per-line payload | Residual against OXID's total |
|---|---|---|
| paypal | `Basket/PurchaseUnitBuilder::build` | `reconcile()`: residual ≤ ceiling → folded into `handling` (+) or `discount` (−); larger → `null` → total-only purchase unit |
| mollie-payment | `Service/MollieLinesBuilder` | appends a `Rounding adjustment` line up to an absolute + relative ceiling; beyond that throws (the lines are incomplete) |
| stripe | `Service/CheckoutSessionService::buildLineItems` | itemised sum ≠ `totalGross`, or discounts present → one `Order Total` line |

## What payment-base offers for I2

`Math\Vat\PerLineVatCalculator` (via `PerLineVatCalculatorInterface`), `TaxableLine` and `VatBreakdown`:
per-line VAT, rounded per line, grouped by rate. A tool for building a provider payload — **never** an
input to an OXID total. `Math\Money\LineItemAmount` and `MinorUnitConverter` cover the per-line totals
and the integer-cents wire representation (see `docs/engineering/money-arithmetic.md`).

## How to check a change against this invariant

- `grep -rn "PriceList\|setPrice\|setVat\|_aList" src/` in payment-base → only reads, no `extend`.
- A provider payload builder has a test where the per-line sum differs from OXID's total by a cent and
  the payload still carries OXID's total, with the residual visible in the adjustment element.
- The admin has no setting about rounding.
