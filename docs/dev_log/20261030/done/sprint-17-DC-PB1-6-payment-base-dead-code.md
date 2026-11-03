# Sprint 17 — DC-PB1…DC-PB6 (payment-base): code that never ran, removed

**Branch:** `b-7.4.x-VR-17-dead-code` (from `b-7.4.x` @ `d062955`) · **Date:** 2026-11-03
**Status:** done on the branch, not pushed, awaiting merge approval. Independent of `b-7.4.x-VR-16-deploy-hygiene`
(no shared files except `CHANGELOG.md`, both add sections under `[Unreleased]`).

| Batch | Commit | Removed | Tests |
|---|---|---|---|
| DC-PB1 | `09a31e5` | 42 files under `src/Mcp` + `Mcp/docs` (server, handlers, OAuth/JWT validators, notification/callback service, UCP, 6 ACP tools, catalog interfaces). Kept: ACP checkout chain, `Http/ApcuRateLimiter` + `RateLimiterInterface` (Stripe) | 8 test files deleted |
| DC-PB2 | `0fa46a4` | `WebhookProcessor`, `WebhookIdempotencyChecker`, `WebhookEventDispatcher` + `WebhookEventHandlerInterface`, `WebhookRequestParser`, `WebhookReceivedEvent` (+ interfaces), `WebhookPayloadSanitizer`, `AbstractWebhookProcessor::isAlreadyProcessed/logWebhookReceived` + ctor arg `$sanitizer` | 6 test files deleted |
| DC-PB3 | `6c09d88` | condition-type registry (6 files), `ContractCondition::setConditionTypeRegistry/agentIdentityVerified/agentConsentConfirmed` | — |
| DC-PB4 | `bef7dac` | `ContractConditionFulfilledEvent`, `OrderCompletedEvent`, `ContractFailedEvent`, `PaymentInitiatedEvent` (+ interfaces), `GenericContractCreationHandler`, `ContractCleanupHandler`, `PaymentAuthorizationHandler` | 6 deleted; `ContractCreationHandlerTest` + `EventListenerProviderTest` moved to fixtures (`tests/Unit/EventSystem/Fixture/`) |
| DC-PB5 | `5f43b2b` | `AbstractPaymentRefundService`, `AbstractPaymentCaptureService`, `PaymentCaptureService`, `Capture/RefundFailedException`, `VoidResponse` | 3 deleted |
| DC-PB6 | `7994a9d` | `OxidShopOrderService::storeOrderMetadata()`, 7 admin lang keys (en + de) | — |

**Numbers:** 80 classes/interfaces removed from `src/`; unit tests 1976 → 1802 (all removed tests covered only removed
code; the two rewritten tests still run). `git diff --stat d062955` on the branch: see the merge request.

**Consumer check** (`consumer_refs` script over stripe, mollie-payment, paypal, one-page-checkout, opalreturns,
opalsubscription, oxid-admin-graphql — src, tests, yaml, twig): 0 live references; 2 commented-out lines in
`stripe/services.yaml:442,454` (Stripe DC-S1 removes them).

**Gates** (php container, branch checkout): Unit 1802 OK (9 skipped) · phpstan no errors · phpcs clean · phpmd clean.
Integration, full suite from the branch (namespace-only autoloader bootstrap: shop libraries, payment-base classes
from the branch): 234 tests, failures = exactly the 7 local baseline failures of `b-7.4.x` (GraphQL admin, environment).

## Left for later (decision or consumer PR first)

- DC-PB7 cross-repo: ACP chain (MCP keep/delete decision), `WebhookLogService` (Stripe factory first),
  `VoidAuthorizationRequestedEvent` (stripe/paypal tests first), `InMemoryRateLimitStore` (mollie tests),
  `RateLimitOverrideInterface`.
- `FraudCheckResponse` `@deprecated` getters — their note promises removal in the next major.
- `OXSTATEREASON` / `OXTERMS` (always NULL) — needs a column-drop migration (destructive, owner).
- `OpenAttemptFinderInterface` — integration-tested query, candidate for the headless retire-by-basket path.
- ~25 public methods without a production caller (list in `reports/deadcode-check-payment-base.md`).
- opalsubscription's four never-dispatched events (`PaymentCaptured`, `PaymentFailed`, `ContractCancelled`,
  `ContractExpired`) — owner decision.
- DC-5 CI gate (dead-code detector) — next.
