# Sprint 15 / S6 — Headless checkout service and GraphQL glue (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## Phase 0 done on the way

- `oxid-esales/graphql-storefront:dev-b-7.4.x` installed into the dev shop, `oe_graphql_base` + `oe_graphql_storefront`
  activated, migrations run (`OEGQL_*` columns on `oxuserbaskets` exist).
- The shop's `composer.json` needed one line: the payment-base path repository now pins
  `"versions": {"oxid-esales/payment-base": "dev-b-7.4.x"}`, because Composer names a path package after its checked-out
  branch and the root requires `dev-b-7.4.x`. Dev-environment only; the shop checkout was already dirty.

## What changed

**Provider-agnostic orchestration — `Checkout\Headless`** (what the MCP tools will drive too)

| Piece | Job |
|---|---|
| `HeadlessCheckoutServiceInterface` + `HeadlessCheckoutService` | `start(HeadlessStartRequest)`: return URLs through the S5 policy → basket scope entered (`basketId`) so `EarlyOrderCreationHandler`'s registry keys by basket → `oxuserbaskets` row → `OEGQL_PAYMENTID` → contract-first handler → AGB (`blConfirmAGB`) → S1 basket provider builds the shop basket → `PaymentContext{basket, user, paymentId, returnUrl, cancelUrl, metadata{basketId, uiMode, headless, sessionId}}` → `handler->processPayment()` (today's "Place Order") → token stamped on the contract (`headless_token`) → scope moves to the contract → `HeadlessStartResult{contractId, contractToken, providerName, orderNumber, redirectUrl, clientSecret, renderMode}`. `return(contractId, token, providerParams)`: token (`hash_equals`), already committed = idempotent, else the provider's resolver through `CheckoutReturnResponder` → `HeadlessReturnResult{status committed/pending/failed, orderId, orderNumber, contractState}`. `cancel(contractId, token)`: token, `PreviousCheckoutAttemptCleaner::clean()` → `HeadlessCancelResult` |
| `HeadlessCheckoutException` | stable `errorCode`s: basket_not_found, payment_not_supported, terms_not_confirmed, return_url_rejected, user_not_found, provider_failed (+ `providerCode`), contract_not_found, invalid_token, no_return_resolver |
| `PaymentHandlerRegistryInterface` + `PaymentHandlerRegistry` | the `oe.payment.handler` services **that implement the new `Adapter\ContractFirstPaymentHandlerInterface`** (marker); `forPaymentMethod`, `forProvider`, `isContractFirst`. Found during the integration test: the OPC module's `StandardPaymentHandler` is tagged the same way for `oxidpayadvance` & co. — without the marker every core payment would have looked contract-first |
| `ReturnResolverRegistryInterface` + `ReturnResolverRegistry` | `!tagged_iterator { tag: oe.payment.return_resolver, index_by: provider }` |
| `Controller\OxidSessionWriter` | payment-base binds `SessionWriterInterface` and `CheckoutReturnResponder` (with the S3 scope) itself |
| `EventSystem\Handler\UserBasketRemovalHandler` | on `ContractCommittedEvent`, delete the `oxuserbaskets` row named by `basket_id`; never on cancel |

**GraphQL-specific glue — `GraphQL\`** (neither autowired nor autoconfigured: payment-base boots without graphql-base)

| Piece | Job |
|---|---|
| `Service\NamespaceMapper` (`graphql_namespace_mapper`) | controllers `GraphQL\Controller` (empty on purpose — mutations are the providers'), types `GraphQL\DataType` |
| `Service\PermissionProvider` (`graphql_permission_provider`) | `PAYMENT_CHECKOUT` for `oxidcustomer`, `oxidnotyetordered`, `oxidadmin`; anonymous = phase 4 |
| `DataType\CheckoutStartResult` / `CheckoutReturnResult` / `CheckoutCancelResult` | GraphQLite `#[Type]` wrappers over the headless DTOs |
| `Subscriber\RefusePlaceOrderForContractFirstPayments` (`kernel.event_subscriber`) | Option B's guard on the storefront's `BeforePlaceOrder`: contract-first payment ⇒ `ContractFirstPaymentCheckout` (graphql-base `Error`, request-error category) naming `<provider>CheckoutStart`; core payment or unknown basket ⇒ untouched |

`README.md` now describes `src/GraphQL/` and `src/Checkout/Headless/` truthfully.

## Red → green

| Test | Covers |
|---|---|
| `Unit\Checkout\Headless\PaymentHandlerRegistryTest` (5) | by payment id, by provider, iterable, **non-contract-first handlers ignored** |
| `Unit\Checkout\Headless\ReturnResolverRegistryTest` (3) | by provider, case-insensitive |
| `Unit\Checkout\Headless\HeadlessCheckoutServiceTest` (18) | start happy path (context, scope, token, result), scope entered before the handler, rejected URL stops everything, unknown basket, non-contract-first payment, AGB on/off, foreign basket translated, provider failure; return happy/idempotent/wrong token/unknown/no resolver/pending; cancel happy/wrong token/settled |
| `Unit\GraphQL\Service\NamespaceMapperTest` (2), `PermissionProviderTest` (2) | graphql-base contracts, directories exist, groups |
| `Unit\GraphQL\Subscriber\RefusePlaceOrderForContractFirstPaymentsTest` (4) | subscription, core passes, unknown passes, contract-first refused with the mutation name |
| `Unit\EventSystem\Handler\UserBasketRemovalHandlerTest` (5) | deletes by `basket_id`, session contract untouched, other events ignored, failed delete does not break |
| `Integration\GraphQL\HeadlessWiringTest` (3) | container resolves the headless services; the guard lets `oxidpayadvance` through; refuses a contract-first payment (skips until a provider declares its handler) |

## Gates

- Unit **1528** green (6 pre-existing skips) · Integration **145** green (2 skips: 1 pre-existing + the guard case above)
- phpcs clean · PHPStan level max No errors · phpmd clean
- Stubs for graphql-base / GraphQLite / storefront / Symfony contracts added to both bootstraps instead of pulling
  `graphql-storefront` (and with it the whole shop) into payment-base's standalone vendor — the repo's established
  pattern for OXID classes.

## For the provider stories (P-Stripe / P-Mollie / P-PayPal)

1. `class XPaymentHandler implements ContractFirstPaymentHandlerInterface` (marker) and stop reading the session in
   `processPayment()`: take basket, user, URLs and `metadata.basketId` from the `PaymentContext`; put `basketId` into the
   `ContractDraftCompletedEvent` context so S1's order service uses the user basket.
2. Tag the return resolver: `- { name: oe.payment.return_resolver, provider: stripe }`.
3. Three mutations in `src/X/GraphQL/Controller`, `#[Right('PAYMENT_CHECKOUT')]`, ~20 lines each over
   `HeadlessCheckoutServiceInterface`, returning the shared `Checkout*Result` types; add the controller namespace through
   the provider's own `graphql_namespace_mapper`.
4. Webhook success handlers call `ContractCommitServiceInterface` (S4) instead of skipping on `!isCommitted()`.
5. Drop the module-local `SessionAdapterInterface` / `SessionWriterInterface` bindings (payment-base owns them now).

## Deferred (documented, not blocking)

- Vouchers on a user basket (`oxvouchers.OEGQL_BASKETID`) and the storefront's delivery-address choice
  (`OEGQL_DELADDRESSID`) are not applied by `UserBasketProvider` yet.
- `CheckoutReturnResponder` keeps its own dispatch (see S4 report).
