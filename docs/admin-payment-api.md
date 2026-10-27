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
| `ADMIN_PAYMENT_VIEW` | `AdminOrder.paymentContract` (state, amounts, transactions) |
| `ADMIN_PAYMENT_CAPTURE` | `adminPaymentCapture`, `adminPaymentVoid` (releasing an authorisation is the reverse of a capture) |
| `ADMIN_PAYMENT_REFUND` | `adminPaymentRefund` |
| `ADMIN_PAYMENT_CONFIG` | defined for the providers' settings screens; no operation of payment-base uses it |

`bin/oe-console oe:graphql-admin:rights-export --path=/tmp/all.json` (without `--core-only`) lists them; the superadmin
holds them, other roles get them through `adminRoleCreate` / `adminRoleModify`.

## `AdminOrder.paymentContract: AdminPaymentContract`

Null for an order that was not paid through a provider module. Fields: `provider`, `state` (`DRAFT`, `NOT_FINISHED`,
`PENDING`, `AUTHORIZED`, `READY_TO_COMMIT`, `COMMITTED`, `FULFILLED`, `CANCELLED`, `EXPIRED`, `FAILED`), `amountAuthorised`,
`amountCaptured`, `amountRefunded`, `currency`, `transactions { id type status amount createdAt providerReference }`, and the
two bounds the screen offers: `amountCapturable`, `amountRefundable` (computed once, in `PaymentContractDetails`, the same
numbers the mutations enforce). No secrets, no raw provider payload; provider plugins add their read-only details with
their own `#[ExtendType(AdminPaymentTransaction)]`.

- Capturable: the contract is `AUTHORIZED`, or `COMMITTED` by a manual-capture provider, and nothing is recorded as captured.
- Refundable: `COMMITTED` / `FULFILLED`, captured minus refunded (half-cent tolerance, `Math\Money\Money`).

## Mutations

All answer `AdminPaymentOperationPayload { paymentContract, errors }` (the payment as it stands afterwards).

| Operation | Right | Step-up |
| --- | --- | --- |
| `adminPaymentCapture(orderId, amount?, idempotencyKey?)` | `ADMIN_PAYMENT_CAPTURE` | yes |
| `adminPaymentRefund(orderId, amount, reason?, idempotencyKey?)` | `ADMIN_PAYMENT_REFUND` | yes |
| `adminPaymentVoid(orderId, idempotencyKey?)` | `ADMIN_PAYMENT_CAPTURE` | no (moves no money) |

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
  a refusal is recorded as the failure it is. The free-text `reason` is a field name, never a value.
- **Scope.** The order must belong to the shop of the request (`oxorder.OXSHOPID`), else `not_found`.
- **Errors** (payload `errors`): `NotFoundError`; `ValidationError { field, code }` with the codes `invalid_key`, `too_long` /
  `invalid_characters` (reason), `amount_invalid`, `exceeds_capturable`, `exceeds_refundable`, `not_capturable`,
  `not_refundable`, `not_voidable`, `in_progress`, `provider_unavailable`, `provider_failed` (the provider threw; the reason is
  in the log, never in the answer), `not_confirmed`. A missing right, session or step-up is a GraphQL error with
  `extensions.errorCode`, as everywhere in the admin API.

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
