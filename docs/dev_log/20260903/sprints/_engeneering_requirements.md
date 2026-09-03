# Engineering requirements — dev_log 20260903

Binding for every sprint in this folder. A sprint doc references this file
instead of repeating the principles. Carried over from
[`20260827`](../../20260827/sprints/_engeneering_requirements.md) with the
examples restated for this day's subject (returning vouchers to the pool when
an order ends).

## Core requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Failing test first, always. Here that means: a test asserting the voucher row is still stamped with `OXORDERID` after an admin storno exists — and fails — *before* any extension code. |
| **DevOps-first** | `composer phpcs`, `composer phpstan` (level max), `composer phpmd`, `phpunit` Unit + Integration all green before every commit. No commit "to be fixed by the next one". |
| **Proven through the UI** | A behaviour a merchant triggers by clicking is not done until a Playwright test has clicked it. Unit tests and DB reads are supporting evidence, never the proof. The admin storno and the admin delete each get a UI test. |
| **SOLID / SRP** | *Release the vouchers of an order* is one collaborator, already existing. The class extension decides **when**; it never writes voucher SQL itself. |
| **SOLID / OCP** | A new way to end an order (a future admin action, another module's flow) reuses `VoucherReleaseInterface` and needs no change to it. |
| **SOLID / DIP** | The `Order` extension resolves `VoucherReleaseInterface` through `ContainerFactory` — OXID models have no constructor DI — never `new`, never the Doctrine class by name. |
| **LSP** | The extension extends `Order_parent`, calls `parent::` and returns the same types. An order with no voucher must behave byte-identically to an unpatched shop. |
| **DRY** | Reuse `releaseVouchers()`. The reset of `OXORDERID`, `OXUSERID`, `OXDISCOUNT`, `OXDATEUSED`, `OXRESERVED` is defined once; a second copy of that SQL is a defect, not a shortcut. |
| **Agnosticism** | No `\Stripe\*`, no `\Mollie\*`, no provider-name literals. A coupon on a plain `oxidinvoice` order must come back exactly like one on a PSP order, in a shop with **no PSP module installed**. |
| **Additive only** | payment-base is consumed by stripe, paypal, mollie, opalreturns and one-page-checkout. Append-only, safe default, kill switch. Consumer test counts must match exactly before/after. |
| **No overengineering** | No partial voucher return, no per-series policy, no "release only if unused elsewhere". One rule: the order ended, its vouchers go back. |
| **Never suppress static analysis** | `@phpstan-ignore-next-line` only for OXID core patterns (virtual `*_parent`, `oxNew`, `Registry::*`). Complexity gets refactored; PHPMD thresholds stay as they are. |
