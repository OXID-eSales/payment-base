# Engineering requirements — dev_log 20260826

Binding for every sprint in this folder. A sprint doc references this file
instead of repeating the principles.

## Core requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Failing test first, always. For this day's sprint that means: a resolver test that returns `null` for a two-method list and the id for a one-method list exists *before* any resolver code. |
| **DevOps-first** | `composer phpcs`, `composer phpstan` (level max), `composer phpmd`, `phpunit` Unit + Integration all green before every commit. No commit "to be fixed by the next one". |
| **SOLID / SRP** | Three separate collaborators: *decide* (is there exactly one auto-assignable method?), *describe* (does that method need user input?), *apply* (write session + basket state). Never one `autoSelect()` god-method on the controller. |
| **SOLID / OCP** | Adding a new payment method — core (`oxidinvoice`, `oxidcashondel`) or PSP — requires **zero** change to the resolver. It consumes the resolved OXID payment list, not a provider registry. |
| **SOLID / DIP** | Consumers depend on `SinglePaymentResolverInterface` / `SinglePaymentAssignerInterface`. The class extension resolves them through `ContainerFactory` (OXID controllers have no constructor DI) — never `new`. |
| **LSP** | The `PaymentController` / `OrderController` extensions extend `*_parent`, call `parent::` first, and return the same types. A shop with ≥2 payment methods must behave byte-identically to an unpatched shop. |
| **DRY** | Auto-assignment reuses `Payment::isValidPayment()` and the same session keys as core `validatePayment()` (`paymentid`, `dynvalue`, `sShipSet`, `_selected_paymentid`). No second, parallel notion of "the chosen payment". |
| **Agnosticism** | payment-base stays provider-agnostic: no `\Stripe\*`, no `\PayPal\*`, no provider-name literals. The feature must work in a shop with **no PSP module installed at all**. |
| **Additive only** | payment-base is consumed by stripe, paypal, mollie, opalreturns and one-page-checkout. Every change is append-only with a safe default and a kill switch; consumer test counts must match exactly before/after. |
| **No overengineering** | No payment-method-priority config, no "auto-select the cheapest", no multi-method skip heuristics. Exactly one rule: *count == 1 and it needs no user input*. |
| **Never suppress static analysis** | `@phpstan-ignore-next-line` only for OXID core patterns (virtual `*_parent` classes, `oxNew`, `Registry::*`). Complexity gets refactored, PHPMD thresholds stay as they are. |
