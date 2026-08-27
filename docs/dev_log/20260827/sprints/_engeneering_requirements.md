# Engineering requirements — dev_log 20260827

Binding for every sprint in this folder. A sprint doc references this file
instead of repeating the principles. Carried over from
[`20260826`](../../20260826/sprints/_engeneering_requirements.md) with the
examples restated for this day's subject (the delivery-set / shipping choice).

## Core requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Failing test first, always. For this day's sprint that means: a resolver test that returns `null` for a two-set list and the id for a one-set list exists *before* any resolver code. |
| **DevOps-first** | `composer phpcs`, `composer phpstan` (level max), `composer phpmd`, `phpunit` Unit + Integration all green before every commit. No commit "to be fixed by the next one". |
| **SOLID / SRP** | Separate collaborators: *decide* (is there exactly one usable delivery set?), *apply* (write session + basket state), *read the flag*. Never one `autoSelect()` god-method on the controller, and never a method that decides shipping **and** payment. |
| **SOLID / OCP** | Adding a delivery set — core or module-provided — requires **zero** change to the resolver. It consumes OXID's already-filtered active-set list, not a registry. |
| **SOLID / DIP** | Consumers depend on `SingleShippingResolverInterface` / `SingleShippingAssignerInterface`. The class extensions resolve them through `ContainerFactory` (OXID controllers have no constructor DI) — never `new`. |
| **LSP** | The `PaymentController` / `OrderController` extensions extend `*_parent`, call `parent::` first, and return the same types. A shop with ≥2 delivery sets must behave byte-identically to an unpatched shop. |
| **DRY** | Auto-assignment reuses core's own writes from `PaymentController::changeshipping()` — the `sShipSet` session variable and `Basket::setShipping()`. No second, parallel notion of "the chosen delivery set". |
| **Agnosticism** | payment-base stays provider-agnostic: no `\Stripe\*`, no `\PayPal\*`, no provider-name literals. The feature must work in a shop with **no PSP module installed at all**. Shipping is not a payment concern — the code must not consult a payment handler to answer it. |
| **Additive only** | payment-base is consumed by stripe, paypal, mollie, opalreturns and one-page-checkout. Every change is append-only with a safe default and a kill switch; consumer test counts must match exactly before/after. **Sprint 06's shipped classes are not refactored** (see sprint 07 §7, D2). |
| **No overengineering** | No carrier-priority config, no "auto-select the cheapest set", no multi-set heuristics. Exactly one rule: *count == 1*. |
| **Never suppress static analysis** | `@phpstan-ignore-next-line` only for OXID core patterns (virtual `*_parent` classes, `oxNew`, `Registry::*`). Complexity gets refactored, PHPMD thresholds stay as they are. |
