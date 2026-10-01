# Sprint 14 — MOL-10: shared "Help" for the OXID contract states (payment-base)

**Date:** 2026-10-01 · **Ticket:** MOL-10 (follow-up: shared approach) · **Branch:** `b-7.4.x-MOL-10-contract-state-help`
**Status:** IN PROGRESS — TDD; merge into `b-7.4.x` on the product owner's word (Mollie's and Stripe's CI install
payment-base from `b-7.4.x`, so this lands first).

## Ask

The contract-state Help that Mollie shipped on its own Settings tab (mollie-payment MOL-10, 2026-09-30) moves to
payment-base: a two-column table — OXID Contract Status · Meaning — on payment-base's Settings tab, and shared parts
the providers build on: Mollie and Stripe add a third, provider-specific column on their Settings tabs, and their
order Payment panels get a "?" icon next to "OXID Contract Status" that opens a popup layer with the provider's
description and the three-column table.

## Design (payment-base owns the words and the markup, providers own their column)

| Piece | One job |
|---|---|
| `Admin\Help\ContractStateHelpRow`, `Admin\Help\ContractStateHelp::rows()` | the eight rows (states shown in the cell + meaning ident), ladder order, built from `ContractState` factories; `draft` omitted |
| `Twig\ContractStateHelpExtension` | Twig function `oe_payment_contract_state_help()` → rows, so every module's template reads one PHP table (tag `twig.extension`) |
| `views/twig/admin/help/contract_state_table.html.twig` | the table; optional third column from `providerLabel` (ident) + `providerStatuses` (state → status text, `''` = none) |
| `views/twig/admin/help/contract_state_hint.html.twig` | the "?" icon + popup layer (intro + table), self-contained CSS/JS, one per panel, keyboard-closable |
| `views/twig/extensions/themes/admin_twig/module_config.html.twig` | payment-base's own Settings tab: Help group after the last group, two columns; delegates every stock group to `{{ parent() }}` (several modules override this template) |
| admin translations EN/DE | `PAYMENT_ADMIN_HELP*` (title, intro, headers, eight meanings, "none"), `PAYMENT_ADMIN_CONTRACT_STATE` → "OXID Contract Status" / "OXID-Vertragsstatus" |

Providers (separate branches, same name): Mollie replaces its own rows class by a provider map (state → Mollie
status) and includes the shared partials; Stripe adds the same with PaymentIntent statuses and an "OXID Contract
Status" row to its panel (it showed none).

## Stories

1. Red: `ContractStateHelpTest`, `ContractStateHelpExtensionTest`, `ContractStateHelpTemplatesGuardTest` (partials +
   override, source assertions as `PaymentAdminTabTemplateGuardTest`), translations in both languages.
2. Green: classes, extension + services.yaml, partials, override, translations.
3. Gates (phpcs/phpstan/phpmd/unit), push, CI; then Mollie and Stripe.

## Done (2026-10-01)

- Red → green: `ContractStateHelpTest` (5), `ContractStateHelpExtensionTest` (2), `ContractStateHelpTemplatesGuardTest` (3);
  Unit suite 1392 green (6 pre-existing skips). Gates: phpcs (warnings counted on the new code), PHPStan, PHPMD green.
- Needed `twig/twig` in require-dev: the standalone unit vendor had no Twig (the shop provides it at runtime).
- Proven in the browser through mollie-payment's admin suite (`tests/MollieAdmin/SharedContractStateHelp.spec.ts`,
  payment-base has no Playwright): payment-base's Settings tab shows the two-column Help group last; Stripe's and
  Mollie's tabs the three-column one; the "?" on both providers' order panels opens the layer, Escape closes it.
- Branch pushed; Mollie's and Stripe's CI pin payment-base at `b-7.4.x` — merge this first.
