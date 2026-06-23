# Money & floating-point arithmetic

**Audience:** anyone doing arithmetic on a price, amount, tax, capture, or refund value in
`payment-base` or in any PSP module that targets it (`stripe`, `paypal`, …).

**Canonical home:** `source/extensions/payment-base/docs/engineering/money-arithmetic.md`.
PSP modules link here instead of re-stating the rules.

---

## TL;DR — the four rules

1. **On the PSP wire, money is an integer in minor units** (cents). Convert with
   `MinorUnitConverter`, never a hand-coded `* 100` / `/ 100`.
2. **In the OXID shop domain, money is a `float`.** That is unavoidable (OXID `Price` objects are
   floats). Treat every float amount as *approximate* and never compare two of them with `==`.
3. **Compare float amounts through `Money`**, which absorbs IEEE-754 noise with one shared
   half-cent tolerance. Never re-derive an epsilon inline.
4. **Keep arithmetic in a pure, tested unit**, not inline inside a service/controller/array literal.
   The `Math\Money\*` and `Math\Vat\*` classes are the template.

---

## Why floats are dangerous for money

PHP `float` is an IEEE-754 binary double. Decimal fractions that look exact in base-10 are *not*
representable in base-2, so arithmetic drifts:

```php
0.1 + 0.2 === 0.3;       // false  → 0.30000000000000004
19.99 * 100;             // 1998.9999999999998, not 1999
(int) (19.99 * 100);     // 1998   ← a lost cent
var_dump(0.58 - 0.57);   // float(0.009999999999999964)
```

For money this surfaces as cent-off totals, `==` comparisons that fail, `(int)` casts that lose a
cent, and drift that accumulates over many basket lines. The defences are: **integer minor units**
where we control the representation (the PSP wire), and **rounding + a tolerance** where we don't
(OXID floats).

---

## The `Math\Money` toolkit

All pure, `final`, static — no state, no injected dependency (a swappable interface would add a
test-double layer with no benefit; test by calling the static methods directly).

### `MinorUnitConverter` — major ↔ minor units

Currency-aware. Knows 0-decimal (JPY, KRW…), 2-decimal (EUR, USD…), and 3-decimal (BHD, KWD…)
currencies; unknown/empty currency defaults to 2 decimals (shop-agnostic — do **not** hardcode EUR).

```php
use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;

MinorUnitConverter::toMinorUnits(19.99, 'EUR');  // 1999  (uses (int) round(), not truncation)
MinorUnitConverter::toMinorUnits(1000.0, 'JPY'); // 1000  (0-decimal: ×1, not ×100)
MinorUnitConverter::toMinorUnits(1.234, 'BHD');  // 1234  (3-decimal)
MinorUnitConverter::toMajorUnits(1999, 'EUR');   // 19.99
MinorUnitConverter::decimalsFor('JPY');          // 0
```

> This replaced the two byte-identical, currency-blind `(int) round($x * 100)` helpers that lived in
> the ACP/UCP MCP formatters (wrong for JPY/BHD) and the Stripe module's duplicated converter.
> Stripe's `AmountConverter` is now a thin facade that delegates here, so the currency lists live in
> exactly one place.

### `Money` — tolerant comparisons

One shared half-cent epsilon for every float-amount comparison. Use these instead of `==`, `<=`,
`>=`, or an inline `abs($a - $b) < 0.005`.

```php
use OxidEsales\PaymentBase\Math\Money\Money;

Money::HALF_CENT_EPSILON;            // 0.005
Money::equals($a, $b);               // |a - b| < ε
Money::greaterThan($a, $b);          // a >  b + ε   (a is really bigger, not just drift)
Money::atLeast($a, $b);              // a >= b - ε   (a reaches b, allowing a sub-ε shortfall)
Money::atMost($a, $b);               // a <= b + ε   (a fits in b, allowing a sub-ε overshoot)
```

Half a cent is tight enough never to collapse a real partial amount into a full one, loose enough to
absorb accumulated drift. It replaced three private `0.005` constants
(`CaptureRefundTracker::FULL_REFUND_EPSILON`, `RefundIntentHandler::FULL_SUM_EPSILON`,
`CaptureService::AMOUNT_EPSILON`).

### `LineItemAmount` — per-line totals

`price × quantity` as a pure VO, extracted from `ContractService::extractProductItems()` so the
multiplication is tested rather than inline in an array literal.

```php
use OxidEsales\PaymentBase\Math\Money\LineItemAmount;

$line = LineItemAmount::forQuantity($unitGross, $unitNet, $unitVat, $qty);
$line->totalPrice; $line->netPrice; $line->vatValue;
```

### `Math\Vat\PerLineVatCalculator` — per-line VAT

Pre-existing (STRP-157). Per-line VAT with configurable precision; rounds **each line** then sums,
which deliberately diverges from OXID's sum-then-round (see its characterization tests for the
over-collection trade-off). Use it via `PerLineVatCalculatorInterface`.

---

## Rules in practice

**Converting to a PSP wire amount** — always currency-aware, always via the converter:

```php
// ✅
$cents = MinorUnitConverter::toMinorUnits($amount, $currency);
// ❌ currency-blind, truncation-prone
$cents = (int) ($amount * 100);
```

**Multiplying before converting** — multiply integer cents by an integer quantity, so the product is
exact integer arithmetic (no float × float):

```php
// ✅  cents first, then × qty
$sumCents += MinorUnitConverter::toMinorUnits($unitPrice, $currency) * $quantity;
// ❌  float product, then convert (drift before rounding)
$sumCents += MinorUnitConverter::toMinorUnits($unitPrice * $quantity, $currency);
```

**Comparing two float amounts** — never bare operators:

```php
// ✅
if (Money::greaterThan($requested, $remaining)) { /* real over-spend */ }
// ❌  drift can make an exact-remaining request look like an over-spend
if ($requested > $remaining) { ... }
```

**Doing arithmetic at all** — put it in a pure unit with a test, not inline. If you find yourself
writing `$x * $qty` or `$captured - $refunded` inside a service method, that is a missing
`Math\Money` (or `Math\Vat`) unit.

---

## Why not BCMath?

BCMath (arbitrary-precision decimal arithmetic on number-strings) is the textbook answer to float
money. It is `ext-bcmath`-loaded in this project's container. We deliberately **do not** use it:

- **The wire path already has a better answer:** integer minor units are exact, fast, and the
  industry-standard representation for payment amounts. Rewriting them in BCMath gains nothing.
- **The OXID-float path is correct by contract:** amounts arrive pre-rounded from OXID `Price`
  objects; `round()` + `Money`'s tolerance handle the residue, and `PerLineVatCalculator` pins its
  own rounding behaviour with characterization tests.
- **BCMath is verbose and lossy at the edges** (string plumbing everywhere; `bc*` truncates rather
  than rounds below scale on PHP < 8.4) — it would add risk and noise without fixing a real defect.

**When to revisit:** only if a concrete decimal-precision defect is observed in the OXID-float VAT
path. If so, scope it to a single string-backed `Money` value object (fixed scale, BCMath internally)
behind the seams above — never sprinkle raw `bc*` calls across services. Declare `ext-bcmath` in
`composer.json` at that point.

---

## Background

Full inventory, per-file findings, and the BCMath trade-off table:
`stripe/docs/oe_payments_docs/daniil_dev_log/20260622/reports/02-floating-point-math-code-review.md`.
The `Math\Money` units were extracted in Sprint 129 (2026-06-22).
