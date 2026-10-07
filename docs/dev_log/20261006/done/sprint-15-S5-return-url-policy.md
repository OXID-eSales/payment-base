# Sprint 15 / S5 — Return-URL policy for headless clients (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## The problem

The Twig checkout builds its own PSP return URLs. A headless client hands them in with `CheckoutStart`. A crafted
`returnUrl` would bounce a shopper who just paid to a page that also learns their contract id. Each provider would
otherwise solve this on its own.

## What changed

| Piece | Job |
|---|---|
| `Checkout\ReturnUrl\ReturnUrlPolicyInterface` | `assertAllowed(url): url` (throws), `isAllowed(url): bool` |
| `Checkout\ReturnUrl\AllowListReturnUrlPolicy` | absolute `http`/`https` only; scheme checked before host (`javascript:` is "bad scheme", not "relative"); no credentials in the authority; origin (scheme + lowercase host + non-default port) must be the shop's or a listed one |
| `Checkout\ReturnUrl\ReturnUrlRejectedException` | `not_absolute` / `scheme_not_allowed` / `credentials_not_allowed` / `origin_not_allowed`, carries the URL |
| `Checkout\ReturnUrl\ReturnUrlSettingsInterface` + `ReturnUrlSettings` | reads `sPaymentBaseHeadlessReturnOrigins`, splits on commas / whitespace / newlines; unreadable store ⇒ none (shop only) |
| `metadata.php` + EN/DE labels | new group `headless` ("Headless checkout (GraphQL, apps)"), string setting, default `''` |
| `services.yaml` | settings + policy bound (policy public: provider entry points resolve it) |

## Red → green

- `Unit\Checkout\ReturnUrl\AllowListReturnUrlPolicyTest` (8 + 12 data sets): shop always allowed whatever path/query and
  case; listed origins incl. port; entry without scheme = https; no entries ⇒ shop only; default ports are the same
  origin; rejected: foreign origin, lookalike host, other scheme or port on the shop host, relative, scheme-relative,
  empty, `javascript:`, `data:`, credentials (both as a disguise and on the shop host), backslash trick.
- `Unit\Checkout\ReturnUrl\ReturnUrlSettingsTest` (4): split and trim, empty, whitespace-only, unreadable ⇒ none.

## Gates

- Unit **1488** green (6 pre-existing skips) · Integration **142** green (1 pre-existing skip; `oe:module:apply-configuration` run so the setting exists in the dev shop)
- phpcs clean · PHPStan level max No errors · phpmd clean

## Notes

- The PHPStan stub for `ModuleSettingServiceInterface::getString()` said `string`; the real facade returns
  `Symfony\Component\String\UnicodeString`. Stub corrected (first `getString()` caller in payment-base).
- The policy compares origins, not URLs: the client owns path and query (it needs `contract_id` / `contract_token`
  there). Allow-list entries with a path are reduced to their origin.
