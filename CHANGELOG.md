# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

## [Unreleased]

### Added (VR-19) - shop-wide trusted proxies for the webhook caller's address
- Module settings `sPaymentBaseTrustedProxies` (addresses / CIDR ranges of the reverse proxies in front of the shop;
  empty = none) and `sPaymentBaseClientIpHeader` (default `X-Forwarded-For`), group "Webhooks".
- `Webhook\Guard\TrustedProxyClientIp` is a public service built from them (`TrustedProxySettings`): the address
  every provider's webhook rate limit keys on. A proxy's header is believed only when the request comes from a
  configured proxy; an unreadable setting store trusts none (REMOTE_ADDR).

### Added (VR-16) - `oe-payment-base-schema-check`, the deploy gate for the database schema
- `vendor/bin/oe-payment-base-schema-check` asks the shop's migration wrapper `migrations:up-to-date oe_payment_base`:
  exit 0 up to date, 1 migrations pending, 2 cannot check (no wrapper, shop not set up). It never skips. Run it after
  `oe-payment-base-migrate` and before the shop takes traffic. pay1 ran two days three migrations behind; the first
  sign was an order mail sent before the payment.

### Changed (VR-16) - an order whose mails cannot wait on its contract is refused, not mailed at once
- `OxidShopOrderService::createOrder()` throws `ShopOrderException` (`order_mails_not_kept`) when the rendered order mails
  cannot be kept on the contract: no contract row, the store fails, or no store. Before, the mails went out at once -
  "thank you for your order" before the payment, logged as a warning nobody saw. The rewrite path already worked this
  way; both now share one refusal. The exception carries the order id, the contract id and the cause's class, never its
  message. An order without a contract id does not defer its mails at all: no payment to wait for, core mails it.
- `DoctrineContractOrderMailStore`: a database error says what failed and its SQLSTATE; the driver message stays on the
  previous exception. Older DBAL versions put the bound parameters into that message - the whole rendered mail, which
  reached `oxideshop.log` (~105k lines on pay1).

### Removed (VR-16)
- The `$mailSender` argument of `Adapter\OxidShopOrderService` (only the send-at-once fallback used it). A
  `services.yaml` override that passes it must drop it. `ShopMailSenderInterface` itself stays: the commit leg sends
  the kept mails with it.
### Removed (VR-17) - code that never ran
Found by the dead-code check of 2026-11-03 (`docs/dev_log/20261030/reports/deadcode-check-payment-base.md`): no
production caller in payment-base or in any of the seven in-house consumers (stripe, mollie-payment, paypal,
one-page-checkout, opalreturns, opalsubscription, oxid-admin-graphql). Formally a break of the library API.
- MCP/UCP transport, 42 files under `Mcp/` (server, request and notification handlers, OAuth/JWT validators - the JWT
  one never verified a signature -, agent callbacks, UCP, the six ACP tools, catalog interfaces) and `Mcp/docs`. Kept:
  the ACP checkout chain (`AgentContext`, `AcpCheckoutServiceInterface`, `AbstractAcpCheckoutService`,
  `AcpResponseFormatter`) that the providers register, and `Http/ApcuRateLimiter` + `RateLimiterInterface` (Stripe).
- The pre-claim webhook pipeline: `Webhook\WebhookProcessor`(+I), `WebhookIdempotencyChecker`(+I),
  `WebhookEventDispatcher`(+I), `WebhookEventHandlerInterface`, `WebhookRequestParser`(+I),
  `EventSystem\Event\Payment\WebhookReceivedEvent`(+I), `WebhookPayloadSanitizer`, and
  `AbstractWebhookProcessor::isAlreadyProcessed()` / `logWebhookReceived()` with the constructor's third argument
  `$sanitizer`. Webhooks run through `AbstractWebhookProcessor::process()` and `claimEvent()`; Stripe and Mollie never
  passed the sanitizer.
- The condition-type registry: `Contract\ConditionTypeRegistry`(+I), `ConditionTypeRegistryBootService`,
  `ConditionTypeProviderInterface`, `Contract\Provider\{Core,Agent}ConditionTypeProvider`, and
  `ContractCondition::setConditionTypeRegistry()`, `agentIdentityVerified()`, `agentConsentConfirmed()`. Nothing
  booted the registry, so the two agent factories threw "Invalid condition type" whenever called.
- Events nothing dispatched - `ContractConditionFulfilledEvent`, `OrderCompletedEvent`, `ContractFailedEvent`,
  `PaymentInitiatedEvent` (each with its interface) - and handlers no `services.yaml` registers -
  `GenericContractCreationHandler`, `ContractCleanupHandler`, `PaymentAuthorizationHandler`. The abstract
  `ContractCreationHandler` stays (the providers extend it). Kept although never dispatched: `PaymentCapturedEvent`,
  `PaymentFailedEvent`, `ContractCancelledEvent`, `ContractExpiredEvent` - opalsubscription listens for them (open
  decision, sprint 17).
- The capture/refund templates no provider extends: `Service\AbstractPaymentRefundService`,
  `AbstractPaymentCaptureService`, `PaymentCaptureService`, `Service\Exception\{Capture,Refund}FailedException`, and
  `Adapter\Response\VoidResponse` (replaced by `CancellationResponse` long ago). `Adapter\PaymentAdapterInterface` stays:
  Stripe implements it.
- `OxidShopOrderService::storeOrderMetadata()` (private; commented-out "Option 1/2" and a debug log) and seven admin
  language keys no template uses (`PAYMENT_ADMIN_ACTION_FAILED`, `_ACTION_OK`, `_AMOUNT`, `_CAPTURED`, `_CONTRACT_ID`,
  `_PROVIDER_ORDER`, `_REFUNDED`).
### Added (VR-19 W2) - one webhook guard chain for the providers
- `Webhook\Guard\`: `WebhookGuardInterface`, `WebhookGuardResult`, `WebhookHttpRequest`, `WebhookGuardChain`,
  `HttpsGuard`, `PayloadSizeGuard`, `RateLimitGuard` (provider + client key in the shared `RateLimitStoreInterface`),
  `IpAllowlistGuard` (IPv4 and IPv6, nothing allowed implicitly), `IpRange`, and `TrustedProxyClientIp` (a proxy's
  client-IP header is believed only from a configured proxy range; none configured = `REMOTE_ADDR`). Replaces the three
  diverged provider copies - PayPal's rate limiter counted in the guard object and never limited, Mollie keyed on
  `REMOTE_ADDR` behind Cloudflare, PayPal allowed every private address. The providers move onto it in sprint 19 W3-W5;
  nothing is wired in `services.yaml` yet.

### Fixed (VR-19) - a webhook delivery that crashed stays retryable
- `AbstractWebhookProcessor::process()` catches any `Throwable`, not only `Exception`: a `TypeError` in a handler (a
  stale DI container on pay1, 2026-10-30) ends the delivery as `failed` like an exception does. Before, the row stayed
  `claimed`, every retry of the PSP was answered `200 skipped: Already processed`, and the payment never committed.
- `DoctrineWebhookLogRepository::claimEvent()` also takes over a row still `claimed` after ten minutes - a fatal error
  or a timeout never reaches the catch, and no request runs that long. One guarded `UPDATE` (status and age), so two
  concurrent retries cannot both win; fresh claims and processed rows stay exclusive. Follows sprint 13's re-claim of
  `failed` rows.
### Fixed (VR-20) - both commit legs check the confirmed amount; a contract never guesses its money
- `CheckoutReturnResponder` (the browser return) refuses a provider amount or currency that differs from the contract,
  like `ContractCommitService` (the webhook) always did: nothing is dispatched, the contract stays open, the refusal is
  logged with both figures. One rule for both legs: `Service\Commit\ConfirmedAmount::matches()` (half a cent of float
  noise tolerated). An already-processed return carries no amount and is not checked.
- `ContractService` builds the contract amount from the basket price strictly and the currency through
  `ShopCurrency::nameOf()`: no price or no currency refuses the contract instead of defaulting to 0.00 / EUR.
### Added (VR-21 F3) - `Refund\ProviderRefundLedgerInterface`: every provider refund booked once
- `book(contractId, providerRefundId, amount, currency, source)` books a refund the provider made on its contract
  exactly once, keyed by the provider's refund id, whichever path reports it first (admin action, webhook, refund
  intent): one DB transaction locks the contract row, writes the `refund` transaction row, then adds to the
  contract's refunded total - both or neither. A second report of the same id answers `RefundBooking::duplicate()`
  and changes nothing. Refused: empty refund id, non-positive amount, a currency other than the contract's, an
  unknown contract. Public service; the providers move their refund bookings onto it (sprint 21 F4-F6).
- Migration `Version20261103100000`: unique `(OXCONTRACTID, OXTYPE, OXTRANSACTIONID)` on `oe_payments_transaction`.
  Rows without a provider id (NULL) are not affected. A shop that already holds duplicates is stopped and they are
  named (none on pay1 or the dev shop, 2026-11-03).

### Fixed (VR-21) - a refund intent cannot be retargeted, overshoot, or lose decimals
- `RefundIntentHandler`: the intent's correlation keys (e.g. opalreturns' `returnId`) still travel in the provider's
  context, but no longer replace `providerName`, `contractId`, `orderId` or `amount` - before, a colliding key
  retargeted the provider action while the logs showed the base values.
- A refund of captured money above the contract's remaining refundable amount is refused (logged, nothing
  dispatched). When no capture amount was recorded the provider stays the bound, as before.
- The kept amount of a partial capture is rounded to the currency's own decimals (`MinorUnitConverter`), not to two:
  KWD 10.000 - 3.333 keeps 6.667.
### Fixed (VR-22 A2) - two admin capture / refund calls can no longer both get a fresh claim
- `IdempotencyRepositoryInterface::claim(IdempotencyRecord, DateTimeImmutable $now): bool` (new; implementers add it):
  insert, or take over a record whose expiry is before `$now` (ran out, or released) in one guarded UPDATE. Two
  concurrent claims of one key cannot both win. `OperationIdempotency::claim()` uses it instead of
  look-up-then-save, where two re-claims after a release both received FRESH and both called the PSP.
- `DoctrineIdempotencyRepository::findByKey()` no longer answers `null` on a database error (that read as "fresh, go
  ahead"); the error surfaces. Stripe's `IdempotentExecutor` then fails the operation instead of calling Stripe.

### Changed (SEAL-1) - the sealed client config runs on the platform's shared primitives
- `ClientConfig\Contract\SealPrimitivesInterface` (ECDH P-256 key from a raw point, shared secret, raw public key,
  AES-256-GCM): `ClientConfigSealer` takes them as an optional second argument. `BuiltInSealPrimitives` is the OpenSSL code that was
  inside the sealer, so payment-base keeps working alone; `SealedChannelSealPrimitives` delegates to the crypto core of the optional
  module `oxid-esales/sealed-channel` (`P256`, `GcmBox`). `SealPrimitivesFactory` picks by what the autoloader finds: no module
  setting, no activation order. **The envelope (version 1, `docs/payments-keys.md` of oxid-fe-user) and the query
  `paymentClientConfig` are unchanged**; both primitive sets reproduce the shared vector byte for byte.
- R5 proof: `SealedConfigWithAndWithoutTest` seals the vector in four fresh PHP processes (as installed, without GraphQL,
  without the sealed-channel module, without either; the named namespaces are not found at all). payment-base does not
  require the sealed-channel module; the PHPStan bootstrap stubs its two classes for the analysis of payment-base alone.

### Changed (PSP-SWITCH) - the in-flight replay rejoins only an attempt of the same provider
- `Checkout\InFlightCheckoutAttemptResolverInterface::resolve(string $provider, float $liveBasketTotal)` (was
  `resolve(float)`): an open attempt of another provider is not in flight. Before, a Mollie start after a PayPal cancel
  replayed the PayPal approval URL, and the order kept `OXPAYMENTTYPE = oe_payments_paypal`. Callers send their provider
  name (Mollie: `mollie`). Composer branch alias of `b-7.4.x` is now `1.6.x-dev`.

### Removed (CFG-1) - settings change in the classic admin only
- `GraphQL\Admin\Service\AdminSuperadminCheckInterface` and `OxidAdminSuperadminCheck` (never released): the provider
  modules no longer have a GraphQL operation that writes a key, a secret or a connection, so nothing asks for a superadmin.
  `ADMIN_PAYMENT_CONFIG` is described as read-only; it still guards the providers' configuration queries. Capture, refund,
  void and the contract / transaction reads are unchanged. After upgrading: `bin/oe-console oe:graphql:cache-clear`.

### Admin payment API parity (PAY-1) - minor, additive
- `AdminPaymentContract.contractId`, `AdminPaymentTransaction.currency`.
- New query `adminPaymentContractStateHelp(provider, language): AdminPaymentStateHelp` (right `ADMIN_PAYMENT_VIEW`): the
  contract-state Help with translated meanings (en/de) and, per provider, its status column. New
  `Admin\Help\ContractStateHelpProviderInterface` (tag `oe.payment.admin_state_help`), `HelpTranslatorInterface`.
- `AdminPaymentContract.actionOptions` (`refundReasons`, `voidReasons`, `refundDescription`, `reasonMaxLength`) from the new
  optional `Admin\Contract\AdminActionOptionsProviderInterface` of a panel provider. New optional mutation arguments
  `adminPaymentCapture(reason)`, `adminPaymentVoid(reason)`, `adminPaymentRefund(description)`; new error code `reason_invalid`,
  `too_long` / `invalid_characters` also for `description`.
- New optional `Admin\Contract\AdminActionBoundsProviderInterface`: a provider's live capture / refund limits replace the
  local numbers in the read fields and the mutation checks; unreadable bounds fail closed (`provider_unavailable`).
- New `Admin\Panel\AdminActionRefusedException`: the admin API answers `provider_refused` with the provider's sentence; the
  classic dispatcher shows it through the shop's error display (new optional constructor argument
  `AdminRefusalFeedbackInterface`) and does not fail.
- Internal: `ProviderActionRunnerInterface::run()` returns a `ProviderActionOutcome` (was a string); `PaymentContractDetails`
  and `PaymentTransactionDetails` gained constructor arguments (`currency` is the fifth of the transaction).
- Providers that implement none of the new interfaces get the same answers as before; nothing was removed. After upgrading:
  `bin/oe-console oe:graphql:cache-clear`.

## [v1.6.0] - 2026-10-28

### Migrations run after `composer install` / `composer update`
- New `vendor/bin/oe-payment-base-migrate` (composer `bin`): runs the `oe_payment_base` suite through
  `oe-eshop-db_migrate`. Add it to the project's `post-install-cmd` and `post-update-cmd` (see README) - composer runs only
  the root package's scripts. It skips while the shop is not set up; afterwards a failing migration fails the composer run.
- Removed `bin/run-migrations.sh` (it called plain `doctrine-migrations` and was not hooked into anything).

### Order mail waits on the contract, no table of its own (STRP-176 follow-up)
- **Changed:** the order mails rendered at early order creation wait on their contract row, not in
  `oe_payments_order_mail`. Three new nullable columns on `oe_payments_contract` (migration `Version20261028100000`):
  `OXORDERMAILSTATE` (`pending`, `sending`, `sent`, `failed`, `dropped`; `NULL` = nothing deferred), `OXORDERMAILS` (the
  rendered mails as ASCII JSON, cleared once sent or dropped) and `OXORDERMAILSENTAT`. The columns are not part of the
  `PaymentContract` entity: a contract `save()` never writes them and they never bump `OXVERSION`.
- New `Repository\ContractOrderMailStoreInterface` (`DoctrineContractOrderMailStore`): `stash`, `claim` (pending →
  sending, skipped for a stornoed order), `markSent`, `markFailed` (keeps only the mails that did not go out),
  `dropByOrderId`. New value object `Mail\ClaimedOrderMails`.
- The `Order` extension only captures the mails now; `Mail\DefersConfirmationMailInterface` gains `takeDeferredMails()`.
  `Adapter\OxidShopOrderService` keeps them on the contract named in `metadata.contract_id` (new optional constructor
  arguments `$orderMails` and `$mailSender`). Without a contract id, a contract row or a working store, the mails are
  sent at once and the reason is logged - never lost silently.
- `OrderConfirmationMailHandler` claims the mails by contract id, sends them and records `sent` (or `failed`, with only
  the unsent mails kept for a resend by hand). `DoctrineNotFinishedOrderRepository` takes the new store as its optional
  second constructor argument.
- **Migration:** mails still waiting in `oe_payments_order_mail` are moved to the contract of their order (a claimed but
  unsent mail becomes `failed`, so it is never sent twice by itself); rows whose order or contract no longer exists are
  dropped with the table. `down()` recreates the empty 1.5.0 table: mails waiting on contracts are lost on a downgrade.
- **Removed:** `oe_payments_order_mail`, `Repository\DeferredOrderMailRepositoryInterface`,
  `DoctrineDeferredOrderMailRepository` and `Mail\StoredOrderMail` (all `@since 1.5.0`, used by no provider module).

## [v1.5.0] - 2026-10-27

### GraphQL stays optional
- `InvalidClientPublicKey` and `UnknownClientConfigProvider` no longer extend graphql-base's `Error` (loading either without graphql-base was
  a fatal): they are plain `DomainException`s (`ClientConfigRequestRefused`), and `paymentClientConfig` maps them to the new
  `GraphQL\Exception\ClientConfigRefused` with the same message and category - the client-visible error is unchanged.
  `GraphQLIsOptionalTest` guards the rule for all of `src/`: nothing outside `src/GraphQL/` names a GraphQL package class.

### Admin payment API (A10 part 1, release 1.5.0)
- For `oxid-admin-graphql` (optional - payment-base still installs and passes all suites without it): the rights
  `ADMIN_PAYMENT_VIEW`, `_CAPTURE`, `_REFUND`, `_CONFIG` (tagged `graphql_admin_rights_provider`), `AdminOrder.paymentContract`
  (`AdminPaymentContract`: provider, state, amounts, bounds, transactions), the mutations `adminPaymentCapture`,
  `adminPaymentRefund`, `adminPaymentVoid` (step-up for capture and refund, idempotency on `oe_payments_idempotency`, audit
  through the admin recorder, delegation to the panel providers of the classic Payment tab), and subscribers for the
  admin events `AdminOrderChangeGuard` (no item/payment/delivery change after a capture), `AdminOrderCancelled` (log only)
  and `AdminPaymentOwnership` (the Stripe, PayPal and Mollie rows are provider-owned). See `docs/admin-payment-api.md`.
- `services.yaml` binds `TransactionRepositoryInterface` (the same definition every provider module already has).
- `GraphQL\Service\NamespaceMapper` takes a second optional argument (`adminApiInstalled`).
- Dev: the optional admin classes have shared stand-ins (`tests/Support/admin-api-stubs.php`) for the unit suite and PHPStan.

### Order mail waits for the payment (STRP-176)
- Early order creation (`Adapter\OxidShopOrderService`) no longer mails "thank you for your order" before the customer
  has paid at the PSP. The order asks to defer its confirmation mail (`Mail\DefersConfirmationMailInterface`, implemented
  by the `Order` extension); core still renders the customer and owner mail in `finalizeOrder()`, on a capturing mailer
  (new `Email` extension, `Mail\OrderMailCaptureInterface`), and they are stored in the new table
  `oe_payments_order_mail` (migration `Version20261026100000`, utf8mb4) through
  `Repository\DeferredOrderMailRepositoryInterface`.
- Stored mails are dropped when the order is deleted or cancelled (`Order::delete()` / `cancelOrder()`), and when an
  abandoned checkout is cancelled (`DoctrineNotFinishedOrderRepository::cancelOrder()`, which takes the mail repository as
  an optional second constructor argument).
- Orders that are not created by payment-base (invoice, COD, prepayment, ...) mail from `finalizeOrder()` as before.
- The stored mails go out when the contract commits: new `EventSystem\Handler\OrderConfirmationMailHandler` on
  `ContractCommittedEvent` (priority 5, after the basket removal), through `Adapter\ShopMailSenderInterface`
  (`OxidShopMailSender`: core's `Email`, the SMTP settings of the order's shop). Each mail is claimed before it is sent
  (`OXCLAIMEDAT`), so the return and the webhook leg - or a replayed webhook - send it once. Mails of a stornoed or
  deleted order are never sent. A mail the shop's mailer refuses stays stored, released, and is logged; the commit is
  never affected.
- A deferred order always answers `ORDER_STATE_OK`: a mail that cannot be built or stored is logged, but no longer
  aborts the checkout (`OxidShopOrderService` refuses every other state).
- **Behaviour change for every PSP on payment-base (Stripe, PayPal, Mollie):** the order confirmation mail now arrives
  after the payment is confirmed, not when the customer is sent to the PSP. A checkout cancelled at the PSP sends none.


### Guest checkout (Sprint 140a, release 1.5.0)
- `oxidanonymous` holds the storefront's basket rights (`CREATE_BASKET`, `VIEW_BASKET`, `ADD_PRODUCT_TO_BASKET`,
  `REMOVE_BASKET_PRODUCT`, `ADD_VOUCHER`, `REMOVE_VOUCHER`) and `PAYMENT_CHECKOUT`, besides `PAYMENT_CLIENT_CONFIG`.
- `HeadlessStartRequest` gains an optional `GuestDetails $guest` (e-mail, `GuestAddress` invoice address, optional delivery
  address). `HeadlessCheckoutService::start()` runs, for an anonymous token (a token user that does not exist in the shop),
  as a guest account: `GuestUserServiceInterface` (`Adapter\OxidGuestUserService`: new passwordless account, or the guest
  account of that e-mail again) and `GuestBasketClaimInterface` (`Adapter\OxidGuestBasketClaim`: the anonymous basket,
  which has no owner row, becomes the guest's). New `HeadlessCheckoutException` codes `guest_details_required` and
  `account_exists`; `CheckoutStartResult` is unchanged. The constructor of `HeadlessCheckoutService` takes the two new services
  (wired in `services.yaml`; only relevant for code that builds it by hand).
- Known gap, not new: `UserBasketProvider` reads no delivery address from the basket row (`OEGQL_DELADDRESSID`), for
  logged-in baskets as little as for guests, so the order ships to the invoice address. A guest's delivery address is stored
  (`oxaddress`, and in `OEGQL_DELADDRESSID` where the storefront has the column) so that applying it is one change in one place.

### Shared guest input (Sprint 140b / B2)
- The GraphQL input types of the `guest` argument are defined once, here: `GuestCheckoutInput` and `GuestAddressInput`
  (`GraphQL\DataType\GuestCheckoutInputFactory`, `#[Factory]` methods building `GuestDetails` / `GuestAddress`). The
  provider modules (stripe, mollie, paypal) drop their own copies and take `?GuestDetails $guest` in `<provider>CheckoutStart`;
  GraphQL type names are schema-wide, so exactly one module may define them.
- New `GraphQL\Exception\GuestDetailsRefused` (graphql-base `Error`, request-error category,
  `extensions.errorCode = guest_details_required`): the value objects validate while GraphQLite resolves the arguments,
  before any controller try/catch, so the factories turn the refusal into a client-safe error there.
- CI: the integration workflows clear GraphQLite's schema cache after activating the module (guarded: the command exists only
  with graphql-base active).

### Security
- Contract token hashed at rest (Sprint 138b): `HeadlessCheckoutService` stores only the SHA-256 of the headless
  `contractToken` (`HeadlessTokenHash`) and compares hashes on return and cancel; a plaintext value in the metadata no
  longer authorises. Migration `Version20261022100000` hashes the plaintext values already stored; tokens held by
  clients keep working.
- `ClientConfigGuard` is an allow-list per provider: a provider implementing the new
  `AllowListedClientConfigProviderInterface` declares `publicValueNames()` and any other name is refused. The deny shapes
  now also cover Mollie `live_`/`test_` API keys and `access_` tokens. Providers that only implement
  `ClientConfigProviderInterface` are unaffected (deny shapes only).

- Log hygiene (Sprint 138d, D1): `ReturnUrlRejectedException` no longer puts the rejected URL into its message
  (graphql-base logs every GraphQL error message): `Return URL rejected (<reason>): <scheme://host[:port]>`. The full URL
  is `fullUrl()` (the `$url` property is private now) and is written at debug level only by `HeadlessCheckoutService`.

### Added
- `Order.paymentContract` (Sprint 139c): payment-base extends graphql-storefront's `Order` type (`@ExtendType`,
  `GraphQL\Relation\OrderRelationService`) with `paymentContract { state provider contractId updatedAt }`, `null` for an
  order without a contract. Read by `ContractSummaryQuery` (`PaymentContractSummary` value object). The relation's namespace is
  mapped only when graphql-storefront is installed. Run `oe:graphql:cache-clear` after the update.
- Sealed payment client config (Sprint 15): GraphQL query `paymentClientConfig(provider, clientPublicKey)` returning the
  provider's public SDK values (Stripe publishable key, PayPal client id, Mollie profile id) as an AES-256-GCM envelope
  sealed for the asking browser (ephemeral P-256 ECDH + HKDF-SHA256, bound to provider and bearer token id). Provider
  modules register a `ClientConfigProviderInterface` service tagged `payment_base.client_config_provider`;
  `ClientConfigGuard` refuses secret-looking keys/values. New right `PAYMENT_CLIENT_CONFIG` (also for `oxidanonymous`).

## [v1.3.0] - 2026-10-09

### Added
- Shared "Help" for the OXID contract states (Sprint 14, MOL-10): `Admin\Help\ContractStateHelp` (one row per state a
  checkout can reach, with a translated meaning), the Twig function `oe_payment_contract_state_help()`, the table
  partial `@oe_payment_base/admin/help/contract_state_table.html.twig` (two columns, optional provider column) and the
  "?" hint partial `@oe_payment_base/admin/help/contract_state_hint.html.twig` (popup layer with description + table).
  payment-base's own Settings tab ends with a "Help" group showing the two-column table. Provider modules add their
  column (Mollie, Stripe) and the hint next to "OXID Contract Status" on their order panels.
- Added headles functionality

### Changed
- Admin label `PAYMENT_ADMIN_CONTRACT_STATE` reads "OXID Contract Status" / "OXID-Vertragsstatus".
- `twig/twig` added to require-dev (the Twig extension is unit-tested standalone; the shop provides Twig at runtime).

### Added
- Optimistic concurrency on payment contracts (MOL-17): `oe_payments_contract.OXVERSION` (migration
  `Version20260924120000`), `DoctrineContractRepository::save()` updates only the row version it loaded
  and throws `StaleContractException` otherwise; `ContractStateQueryInterface::findByStateAndProvider()`
  for reconciliation sweeps.
- `InFlightCheckoutAttemptResolverInterface` (MOL-18): answers whether the session already has a
  checkout attempt in flight - an open contract with a provider checkout URL whose order is still
  `NOT_FINISHED` and whose basket total matches the live basket - and hands back that URL so a
  provider's order controller can replay the redirect instead of starting a second attempt.
  `OpenCheckoutAttemptRegistryInterface::peek()` reads the remembered attempt without consuming it;
  `NotFinishedOrderRepositoryInterface::isNotFinished()` reads an order's `OXTRANSSTATUS`.

### Fixed
- A webhook delivery that failed could never be retried: the event id was claimed before processing and a
  `failed` outcome kept the claim, so the PSP's retry was answered "Already processed" (200) and the order
  stayed wherever the failure left it. `claimEvent()` now re-claims a `failed` row atomically (processed and
  in-flight rows stay exclusive), and the failure reason is stored on the log row.
- A paid order could end up without a Refund action (MOL-17): the shopper's return leg and the PSP's
  `paid` webhook both saved the contract row without coordination, and the return leg's stale copy
  overwrote the webhook's `fulfilled` state with `committed`. The save is now versioned (see Added),
  `CheckoutReturnResponder` yields to a newer state instead of overwriting it (reports the committed /
  fulfilled contract's order, re-runs the chain once on a fresh copy when the contract is still open),
  and providers' webhook handlers can retry on `StaleContractException`. Which payment methods were
  affected depended on timing only.
- "Order now" clicked twice no longer writes a phantom order (MOL-18). Core's `finalizeOrder()`
  answers `ORDER_STATE_ORDEREXISTS` when `sess_challenge` already names an order row;
  `OxidShopOrderService` treated that as success and saved the never-loaded `Order` object - a
  second row with no user, no articles, no payment type and total 0, which the shopper was then
  sent to the PSP to pay for. `createOrder()` now throws `ShopOrderException` with code
  `order_exists` and writes nothing.
- A retired checkout attempt now forgets the session's `sess_challenge` when it names the retired
  order (MOL-18). The order row is kept (storno, `CANCELLED`) for a gap-free number sequence, so
  while the challenge still pointed at it core refused every further attempt in the session with
  `ORDEREXISTS`; the phantom order above was what made the retry appear to work.
- The provider checkout URL handed to `PaymentContract::setProvider()` is now persisted
  (`OXPROVIDERDATA` as `{"redirectUrl": ...}`) and restored on load (MOL-18). It used to live only in
  memory, so every contract loaded from the database answered `null` to `getProviderRedirectUrl()`.
- Orders where the shopper ships to their billing address ("use billing address for shipping")
  now carry that address in the order's shipping columns too. Core's `Order::setUser()` only
  writes `OXDEL*` when a separate `oxaddress` row was selected, so every such order — for every
  provider using this module's `OxidShopOrderService` (Mollie, Stripe, PayPal) — left the admin
  *Addresses* tab's Shipping Address section empty; the merchant had to know the convention and
  read the billing block instead. `OxidShopOrderService::setOrderFieldsAfterCreation()` now copies
  billing into the 13 `OXDEL*` columns whenever none was already set by core, immediately before
  its existing save; an order with a separately selected delivery address is untouched. Visible
  side effect: order confirmation e-mails and the thank-you page print a shipping block wherever
  the core template keys on `oxdellname`, so those now show the billing address as shipping for
  these orders too, matching what the admin screen shows.
- Order creation in `OxidShopOrderService` no longer calls `setOrderNumber()`, a method only the
  Stripe and one-page-checkout Order extensions provide. Core's `finalizeOrder()` already draws
  the number; on a shop without one of those modules active every payment-base order creation
  threw "Function 'setOrderNumber' does not exist or is not accessible".

## [v1.2.3] - 2026-09-15

### Added
- `extra.branch-alias` (`dev-b-7.4.x` => `1.2.x-dev`). The consuming modules require
  `>=v1.2`, and their CI installs this package from a path repository, where composer sees
  `dev-b-7.4.x` — a dev branch does not satisfy a numeric range on its own. With the alias it
  does, so no workflow has to carry an explicit `as <version>` pin that goes stale on every
  release.

### Changed
- `UnifiedNamespaceClassmapTest` no longer asserts `extra.oxideshop.target-directory`. Since
  OXID 7 a module is not copied to `source/modules/` at all: it stays in `vendor/` and only
  `assets/` is symlinked to `source/out/modules/<moduleId>`. The key is read by
  `ThemePackageInstaller` alone — `ModulePackageInstaller::getModuleTargetDir()` has no caller.
  The package type and the module id are still guarded.

## [v1.2.2] - 2026-09-15

### Added
- Module logo: `assets/img/logo.png` and the matching `thumbnail` entry in `metadata.php`, so the
  admin module page shows the OXID module logo instead of the shop's generic placeholder. It is
  the same file the One-Page Checkout already used, and it is now the shared default for the
  payment modules.

## [v1.2.1] - 2026-09-15

### Changed
- Packaging for composer publication: PHP requirement raised to `^8.2` (the code uses readonly
  classes), runtime dependencies declared explicitly (`symfony/console`, `symfony/filesystem`,
  `doctrine/migrations`, `ext-json`), the unused `psr/event-dispatcher` requirement dropped and a
  `conflict` with OXID eShop below 7.4 added.
- `metadata.php` version now matches the package version. The obsolete
  `extra.oxideshop.target-directory` and the Smarty-era `templates` entry were removed — since
  OXID 7 the module stays in `vendor/` and Twig resolves `@oe_payment_base/...` from `views/twig`.

### Added
- `.gitattributes`: tests, generated documentation, sprint logs, CI workflows, developer scripts
  and the static analysis configuration are no longer part of the composer package. LICENSE,
  README and this changelog stay in it.

## [v1.2.0] - 2026-09-04

### Added
- Single active payment method or delivery set is assigned automatically and its checkout step is
  skipped (`blPaymentBaseAutoAssignSinglePayment`, `blPaymentBaseAutoAssignSingleShipping`).
- Vouchers return to the pool when an order is cancelled or deleted
  (`blPaymentBaseReleaseVouchersOnOrderEnd`).
- Console command `oe:payments:not_finished:cleanup` collects abandoned `NOT_FINISHED` orders
  (`iPaymentBaseCleanupPeriod`), with a configurable stale-checkout horizon
  (`iPaymentBaseStaleCheckoutMinutes`).
- Optional `idempotencyKey` on `RefundPaymentRequest`.
- A payment that is still settling is shown as a notice on the thank-you page instead of an error.

### Changed
- The fraud audit record is separate from the blocking policy.
- Order finalization is owned by payment-base instead of whichever module was merged last.

### Fixed
- A retried checkout retires the order the previous attempt left behind.
- A return controller can reach the attempt cleaner.
- A committed contract can no longer be expired.

## [v1.1.0] - 2026-08-11

Added iframe feature

## [v1.0.0] - 2026-06-26

## Released

## [v1.0.0-RC1] — 2026-06-20

### Added
- First release
