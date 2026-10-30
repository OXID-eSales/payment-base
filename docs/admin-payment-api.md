# Admin payment API (for `oxid-admin-graphql`)

payment-base adds the payment operations of an order to the admin GraphQL API of `oxid-admin-graphql`, for every
provider at once (Stripe, PayPal, Mollie). It is the headless counterpart of the shared classic "Payment" tab, and it
reuses what already backs that tab: the panel providers the provider modules register (`oe.payment.admin_panel`).

**Everything is optional.** payment-base installs, activates and passes all its suites without `oxid-admin-graphql`.
The admin API's services are reached only through `@?`, the classes of this feature implement nothing of it, and
`GraphQL\Service\NamespaceMapper` maps its controller, types and the `AdminOrder` extension only when
`OxidEsales\GraphQL\Admin\Order\DataType\AdminOrder` exists. `Tests\Unit\GraphQL\Admin\AdminApiIsOptionalTest` proves it:
every service class loads in a PHP process where none of the optional packages exist. After a change of the schema:
`bin/oe-console oe:graphql:cache-clear`.

## Rights

`GraphQL\Admin\Service\PaymentRightsProvider` (tag `graphql_admin_rights_provider`) adds to the catalogue:

| Right | Grants |
| --- | --- |
| `ADMIN_PAYMENT_VIEW` | `AdminOrder.paymentContract` (state, amounts, transactions, action options), `adminPaymentContractStateHelp` |
| `ADMIN_PAYMENT_CAPTURE` | `adminPaymentCapture`, `adminPaymentVoid` (releasing an authorisation is the reverse of a capture) |
| `ADMIN_PAYMENT_REFUND` | `adminPaymentRefund` |
| `ADMIN_PAYMENT_CONFIG` | the providers' configuration queries (read-only); no operation of payment-base uses it |

`bin/oe-console oe:graphql-admin:rights-export --path=/tmp/all.json` (without `--core-only`) lists them; the superadmin
holds them, other roles get them through `adminRoleCreate` / `adminRoleModify`.

## `AdminOrder.paymentContract: AdminPaymentContract`

Null for an order that was not paid through a provider module. Fields: `contractId` (the contract's own id), `provider`, `state` (`DRAFT`, `NOT_FINISHED`,
`PENDING`, `AUTHORIZED`, `READY_TO_COMMIT`, `COMMITTED`, `FULFILLED`, `CANCELLED`, `EXPIRED`, `FAILED`), `amountAuthorised`,
`amountCaptured`, `amountRefunded`, `currency`, `transactions { id type status amount currency createdAt providerReference }`,
`actionOptions` (below), and the two bounds the screen offers: `amountCapturable`, `amountRefundable` (computed once, in `PaymentContractDetails`, the same
numbers the mutations enforce). No secrets, no raw provider payload; provider plugins add their read-only details with
their own `#[ExtendType(AdminPaymentTransaction)]`.

- Capturable: the contract is `AUTHORIZED`, or `COMMITTED` by a manual-capture provider, and nothing is recorded as captured.
- Refundable: `COMMITTED` / `FULFILLED`, captured minus refunded (half-cent tolerance, `Math\Money\Money`).

## Mutations

All answer `AdminPaymentOperationPayload { paymentContract, errors }` (the payment as it stands afterwards).

| Operation | Right | Step-up |
| --- | --- | --- |
| `adminPaymentCapture(orderId, amount?, idempotencyKey?, reason?)` | `ADMIN_PAYMENT_CAPTURE` | yes |
| `adminPaymentRefund(orderId, amount, reason?, idempotencyKey?, description?)` | `ADMIN_PAYMENT_REFUND` | yes |
| `adminPaymentVoid(orderId, idempotencyKey?, reason?)` | `ADMIN_PAYMENT_CAPTURE` | no (moves no money) |

The free texts reach the provider as the classic tab's form fields: `capture_reason`, `refund_reason`, `cancel_reason`,
`refund_description`.

- **Delegation.** `PanelProviderActionRunner` hands `capture` / `refund` / `cancel` and the classic tab's form fields
  (`capture_amount`, `refund_amount`, `refund_reason`) to the panel provider of the contract's provider: the provider's own
  capture, refund and cancel code runs, with its own validation. The provider reports nothing back to this module, so the
  result is read from the contract: if state and amounts did not move, the answer is `not_confirmed`.
- **Idempotency.** `OperationIdempotency` on `oe_payments_idempotency`: the record is inserted before the provider is called,
  the unique index of `OXKEY` decides between two simultaneous identical requests (`in_progress` for the loser). The key is
  the client's `idempotencyKey` (8-64 of `A-Za-z0-9_-`; the SPA sends one per dialog), or, without one, a digest of operation,
  order, amount and the payment's state. A finished record lives 24 hours: a retry with the same key reports the earlier
  success without calling the provider; a refused or unconfirmed call releases its key.
- **Audit.** Through the admin module's recorder (`auditedAdmin`): entity `order`, field names, the amount as the only value;
  a refusal is recorded as the failure it is. The free texts `reason` and `description` are field names, never values.
- **Scope.** The order must belong to the shop of the request (`oxorder.OXSHOPID`), else `not_found`.
- **Errors** (payload `errors`): `NotFoundError`; `ValidationError { field, code }` with the codes `invalid_key`, `too_long` /
  `invalid_characters` (reason), `amount_invalid`, `exceeds_capturable`, `exceeds_refundable`, `not_capturable`,
  `not_refundable`, `not_voidable`, `in_progress`, `reason_invalid`, `provider_unavailable`, `provider_refused` (the provider
  refused with a sentence for the admin; it is the error's message), `provider_failed` (the provider threw; the reason is
  in the log, never in the answer), `not_confirmed`. A missing right, session or step-up is a GraphQL error with
  `extensions.errorCode`, as everywhere in the admin API.

## Settings are not changed through GraphQL (CFG-1)

`ADMIN_PAYMENT_CONFIG` opens a provider's configuration for **reading** (settings, connection and webhook checks). No
provider module has a GraphQL operation that changes its settings, API keys, secrets, webhook registration or connection:
that is the classic admin's module configuration only, and payment-base carries no support for it (the superadmin check
for secret writes was removed). The money operations (capture, void, refund) and their step-up are unchanged.

## What a provider module adds (all optional)

A provider that implements none of these gets the answers of before; every interface lives outside `src/GraphQL`, so a
provider implements it without any GraphQL package (`AdminApiIsOptionalTest` loads them where none exists).

| To offer | Implement | Where it shows |
| --- | --- | --- |
| a provider column in the state Help | `Admin\Help\ContractStateHelpProviderInterface` (`providerName`, `providerLabel`, `providerStatuses` keyed `not_finished` ... `failed`), tag `oe.payment.admin_state_help` | `adminPaymentContractStateHelp(provider)` |
| allowed reasons, description, text limit | `Admin\Contract\AdminActionOptionsProviderInterface::actionOptions(contract): AdminActionOptions` on the panel provider | `AdminPaymentContract.actionOptions`; `reason_invalid`, `too_long` on the mutations |
| live capture / refund limits | `Admin\Contract\AdminActionBoundsProviderInterface::boundsFor(contract): ?AdminActionBounds` on the panel provider | `amountCapturable` / `amountRefundable` and the mutation checks |
| a readable refusal | throw `Admin\Panel\AdminActionRefusedException(code, sentence[, feedbackRecorded])` from `handleAction` | `ValidationError provider_refused`; the classic tab shows it too |

### `adminPaymentContractStateHelp(provider: String, language: String): AdminPaymentStateHelp!`

Right `ADMIN_PAYMENT_VIEW`. `AdminPaymentStateHelp { providerLabel, rows { state, states, meaning, providerStatus } }`: one row per
contract state in ladder order (`COMMITTED` and `FULFILLED` share a row: `states` lists both), the meaning translated from the
module's own lang files in `language` (`en`, `de`; English otherwise, default `en`). `providerLabel` and `providerStatus` are
null when `provider` is omitted, names a module that registers no `ContractStateHelpProviderInterface`, or lists no status for
the row. An unknown provider is not an error.

### `AdminPaymentContract.actionOptions`

`{ refundReasons: [String!]!, voidReasons: [String!]!, refundDescription: Boolean!, reasonMaxLength: Int! }`. A provider that
declares nothing answers `[]`, `[]`, `false`, `255`. A non-empty list is the only set the mutation accepts (`reason_invalid`,
field `reason`); empty means free text. `reasonMaxLength` (1 to 255) is enforced as `too_long` on `reason` and `description`.
Capture reasons are free text (length and control characters only).

### Provider bounds

When the panel provider implements `AdminActionBoundsProviderInterface`, its numbers replace payment-base's local ones
(Mollie's multi-capture remainder, its live refundable amount): the read fields and the mutation rules use the same value.
The provider is asked lazily and at most once per read. A side the provider leaves out keeps payment-base's number. If it
cannot be read (exception or `AdminActionBounds::unavailable()`), both bounds read 0 and a write is refused with
`provider_unavailable`: a bound that cannot be read is never replaced by a guess.

### Typed refusals

`AdminActionRefusedException` carries the provider's machine code (logged) and one human sentence (cut at 255 characters, no
ids, no payloads, no secrets), which is the `provider_refused` error's message. The classic Payment tab does not fail on it:
`PaymentAdminActionDispatcher` shows the sentence through the shop's error display, unless the provider passes
`feedbackRecorded: true` because it already recorded its own classic feedback.

## Subscribers (the admin API's events)

| Event | Subscriber | Behaviour |
| --- | --- | --- |
| `AdminOrderChangeGuard` | `OrderChangeGuardSubscriber` | after a capture, refuses `items`, `payment` and `deliverySet` changes (they recalculate the total); an address correction stays possible |
| `AdminOrderCancelled` | `OrderCancelledSubscriber` | logs a warning naming the next step (void or refund); never moves money |
| `AdminPaymentOwnership` | `PaymentOwnershipSubscriber` | claims `oe_payments_stripe_wallet` (stripe), `oe_payments_paypal` (paypal), `oe_payments_mollie` (mollie), read-only fields `title`, `description`; any other row id is the shop's. PayPal stays ONE payment method: a funding-source id is never claimed (and never a row) |

## Tests

- Unit (`tests/Unit/GraphQL/Admin`): the services, types, subscribers and adapters; the admin module's classes are stood in
  by `tests/Support/admin-api-stubs.php` (also loaded by PHPStan).
- Integration (`tests/Integration/GraphQL/Admin`, shop context, skipped where `oxid-admin-graphql` is absent): the rights in
  the real catalogue, the real schema (`AdminSchemaProbe` builds it the way graphql-base does), the events through the shop's
  dispatcher, capture/refund/void on the real database, and `AdminModuleParityTest`, which pins every member payment-base
  uses against the real admin classes so the stand-ins cannot drift.
- **Money flows are proven with a fake at the panel-provider boundary** (`FakePanelProvider`, `AdapterEffects`), not with
  a provider sandbox: what is proven is everything payment-base decides (bounds, step-up, idempotency, audit, the read-back).
  The movement of money itself is each provider module's own business and suites.
- payment-base has no Playwright suite; the API-level acceptance (rights matrix, step-up) runs from the admin SPA's
  end-to-end suite against the real shop (`oxid-fe-admin`, `tests/e2e`), where `adminAuditLog` is read back too.
