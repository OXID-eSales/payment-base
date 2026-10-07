# Sprint 15 / S7 — ACP on the same headless path (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## The problem

payment-base's MCP/ACP/UCP layer (`src/Mcp/`) was a framework with no implementer: `AbstractAcpCheckoutService` left
`createCheckout()` abstract and documented "build a contract somehow", and `completePayment()` was told to "dispatch
PaymentAuthorizedEvent" by hand. An agent describes items and a buyer — no session, no basket, no user — exactly the
situation S1–S6 solved for GraphQL.

## What changed

| Piece | Job |
|---|---|
| `Checkout\Headless\ContractOpeningServiceInterface` + `ContractOpeningService` | `open(userId, basketId, paymentId, channel)`: basket scope entered → S1 provider builds the shop basket from the `oxuserbaskets` row → `ContractService::createContract()` → `basket_id` + `channel` stamped → `ContractDraftCompletedEvent{paymentId, basketId, sessionId, channel, headless}` through the shared dispatcher (early order, PENDING) → reloaded contract answered, scope moves to it. No PSP session: the agent pays with a delegated token later |
| `Checkout\Headless\UserBasketFactoryInterface` + `UserBasketFactory` | ACP `items` → an `oxuserbaskets` row (owner, title `agent-checkout`, private, `OEGQL_PAYMENTID`) with `addItemToBasket()` per line; refuses empty or malformed items |
| `Checkout\Headless\GuestUserResolverInterface` + `GuestUserResolver` | ACP `buyer` + `fulfillment_address` → the existing account for the e-mail (shop-scoped) or a guest `oxuser` (active, rights `user`, no password, names from buyer or the address's `name`, address fields, ISO-2 → `oxcountryid` via `Country::getIdByCode()`; unknown country refused rather than guessed) |
| `Mcp\Acp\AbstractAcpCheckoutService` | **default `createCheckout()`**: validates items / buyer e-mail → resolve buyer → persist basket → open contract → `acp_agent_id` → `formatCheckout()`; headless refusals become ACP validation errors. New abstract `paymentId()` and `providerName()`. New `commitPaid(contract, authorizationId, providerOrderId, amount, currency, requiresCapture)` → `ContractCommitServiceInterface` with `source: acp`. The four collaborators are optional constructor arguments (a provider wired before this sprint keeps compiling; the default then answers "not wired") |
| `services.yaml` | `ContractServiceInterface` now bound in payment-base (identical to the providers' definition); opening service, basket factory, buyer resolver public |
| `src/Mcp/docs/03-building-provider-modules.md` | section 1 rewritten: implement `paymentId()`, `providerName()`, `completePayment()` with `commitPaid()`; wiring block |

## Red → green

| Test | Covers |
|---|---|
| `Unit\Checkout\Headless\ContractOpeningServiceTest` (5) | contract created from the provider-built basket, stamped, event context keys, scope order (basket before the chain, contract after), reloaded contract answered, unknown basket refused before any contract |
| `Unit\Checkout\Headless\UserBasketFactoryTest` (3) | owner/payment/items persisted in order, bad input refused with nothing written |
| `Unit\Checkout\Headless\GuestUserResolverTest` (6) | reuse by e-mail (case-insensitive), guest account fields, name split from the address, no e-mail refused, unknown country refused, no address still gets an account |
| `Unit\Mcp\Acp\AbstractAcpCheckoutServiceTest` (+5) | default create_checkout happy path, item/buyer validation params, headless refusal → validation error, "not wired" answer, `commitPaid` hands a `PaymentConfirmation{…, source: acp}` to the commit service |
| `Integration\Checkout\Headless\AgentBuyerAndBasketTest` (3) | real shop: guest account created with Germany and reused on the second call; items become a row with one line of amount 3 and the payment; opening service resolves from the container |

## Gates

- Unit **1548** green (6 pre-existing skips) · Integration **148** green (2 skips as in S6)
- phpcs clean · PHPStan level max No errors · phpmd clean

## Notes

- `ContractOpeningService` is not exercised end-to-end against the container on purpose: with Stripe active, the
  PENDING transition would make Stripe's checkout-session handler call the Stripe API. The chain itself is the same
  one every Twig checkout runs; P-Stripe decides what its pending handler does for `channel = acp`.
- The UCP REST profile is untouched (phase 4). `AcpResponseFormatter` is unchanged; `checkout_url` stays empty for
  agent checkouts (no PSP session).

## Follow-up (2026-10-06, CI)

`Integration\Checkout\Headless\AgentBuyerAndBasketTest::testItemsBecomeAUserBasketRowWithThePayment` failed on the
bare CI shop: without graphql-storefront `oxuserbaskets` has no `OEGQL_PAYMENTID`, the factory's write is dropped and
the row answers null. The code is right (the payment travels explicitly through `ContractOpeningService` →
`HeadlessStartRequest::$paymentId`, as the factory's comment says); the test now asserts the column only when it
exists (`2f6544e`).
