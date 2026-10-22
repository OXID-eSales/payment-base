# Headless checkout through the GraphQL Storefront — how a client pays

payment-base exposes the contract-first checkout to the GraphQL Storefront (and to agents through the MCP / ACP layer).
The core storefront mutation `placeOrder` is **not** used for the payments of the Stripe, Mollie and PayPal modules;
each provider module ships three mutations over payment-base's `HeadlessCheckoutService`, and the provider's webhook
ends the order. The analysis behind this ("Option B") is `dev_log/20261002/reports/graphql-placed-order-and-payments.md`;
the implementation is Sprint 15 (`dev_log/20261006/`) and the provider stories in the three modules.

## Choosing the payment method

The payment method is chosen by **which start mutation the client calls**. There is one mutation per provider, and each
names its own OXID payment id, so the client does not have to run the storefront's `basketSetPayment` first:

| You call | Payment id the order gets | Where the shopper pays |
|---|---|---|
| `stripeCheckoutStart` | `oe_payments_stripe_wallet` | Stripe's hosted page (`uiMode: hosted`, default) or the client's embedded Checkout (`uiMode: embedded`, answers `clientSecret`) |
| `mollieCheckoutStart` | `oe_payments_mollie` | Mollie's hosted page; the optional `method` argument (`ideal`, `creditcard`, `klarna`, …) pre-selects a Mollie method, without it Mollie offers all of them |
| `paypalCheckoutStart` | `oe_payments_paypal` | PayPal's approval page |

```graphql
mutation {
  mollieCheckoutStart(
    basketId: "…", confirmTermsAndConditions: true,
    returnUrl: "https://app.example/return", method: "ideal"
  ) { contractId contractToken orderNumber redirectUrl renderMode }
}
```

Two rules around that choice (`HeadlessCheckoutService::paymentIdFor()` and the `BeforePlaceOrder` subscriber):

- If the basket **was** set to a payment with `basketSetPayment`, the mutation must match it: `stripeCheckoutStart` on
  a basket set to `oe_payments_mollie` is refused with `payment_not_supported`. A basket with no payment set takes the
  mutation's payment.
- The core `placeOrder` is refused for any contract-first payment; the error names the mutation to call
  (`Payment "oe_payments_mollie" is handled by the mollie checkout: call mollieCheckoutStart instead of placeOrder`).
  Payments that are not contract-first (invoice, cash on delivery, …) still go the classic way: `basketSetPayment`,
  then `placeOrder`.

To present a choice to the shopper, a client lists the payments the shop offers with the storefront's own basket
payment query, maps each contract-first id to its start mutation, and keeps `placeOrder` for the rest.

## What is the same for every provider

| Step | Mutation | Answer |
|---|---|---|
| open | `<provider>CheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl?, …)` | `CheckoutStartResult { contractId, contractToken, providerName, orderNumber, redirectUrl, clientSecret, renderMode }` — the order exists (`NOT_FINISHED`) and the contract is `PENDING` from here on |
| pay | the shopper pays at the provider; the provider's **webhook** commits the contract and ends the order (`paid` ⇒ committed + paid; an authorization with manual capture ⇒ committed, not paid until the merchant captures) | — |
| read | `<provider>CheckoutReturn(contractId, contractToken, …)` | `CheckoutReturnResult { status: committed · pending · failed, orderId, orderNumber, contractState }` — reports what the webhook left; without a webhook the provider's return resolver settles the payment itself |
| abandon | `<provider>CheckoutCancel(contractId, contractToken)` | `CheckoutCancelResult { cancelled, contractId, contractState }` — the attempt is retired, the order cancelled |

- The **contract token** returned by the start is the client's proof of ownership for return and cancel; a wrong token
  changes nothing (`invalid_token`). The database keeps only its SHA-256 (`HeadlessTokenHash`, in
  `oe_payments_contract.OXMETADATA.headless_token`); the plaintext token exists only in the start answer and in the
  client, so reading the database does not authorise a return or a cancel. A plaintext value in that field (older
  data) never authorises; migration `Version20261022100000` hashes such values in place, and a token the client
  already holds keeps working afterwards.
- `returnUrl` / `cancelUrl` belong to the client and must be under the shop's own URL or an origin listed in the setting
  `sPaymentBaseHeadlessReturnOrigins` (`return_url_rejected / origin_not_allowed` otherwise; the error message names the origin only, never the path or query). Every provider appends
  `contract_id` to the return URL; Stripe adds `session_id`, PayPal `token` and `PayerID`, Mollie nothing.
- Refusals are GraphQL errors with a stable `extensions.errorCode` (`HeadlessCheckoutException`): `basket_not_found`,
  `payment_not_supported`, `terms_not_confirmed`, `return_url_rejected`, `user_not_found`, `provider_failed`
  (+ `providerCode`, e.g. `MOLLIE_UI_MODE_UNSUPPORTED`), `contract_not_found`, `invalid_token`, `no_return_resolver`, `guest_details_required`, `account_exists`
  (guest checkout, below).
- A second start for the same basket retires the first attempt (one open attempt per user basket).
- The headless keys of the request (`basketId`, `uiMode`, `headless`, `sessionId`) cannot be overridden by a provider's
  options (`HeadlessStartRequest::$providerOptions`, used for Mollie's `method`).

## Which provider values reach the browser

`paymentClientConfig(provider, clientPublicKey)` seals a provider's public SDK values for the asking browser. A provider
module implements `AllowListedClientConfigProviderInterface` (an extension of `ClientConfigProviderInterface`) and
declares the value names in `publicValueNames()` (Stripe `publishableKey`, PayPal `clientId`, Mollie `profileId`,
`testMode`). `ClientConfigGuard` refuses any other name, whatever its value, and in every case a value shaped like a
secret (`sk_`, `rk_`, `whsec_`, `access_`, Mollie `live_`/`test_` API keys), a name containing "secret" or an API-key
name, and every value that is not a string or a bool. A provider that only implements the base interface keeps working
under the deny shapes alone; declare the names to get the allow-list.

## Order type extension: `Order.paymentContract`

payment-base extends graphql-storefront's `Order` type (`#[ExtendType]`, class `GraphQL\Relation\OrderRelationService`)
with one field, so an order list shows the payment state without a second channel:

```graphql
{ customer { orders { orderNumber paymentContract { state provider contractId updatedAt } } } }
```

| Field | Meaning |
|---|---|
| `state` | the contract state: `pending`, `committed`, `fulfilled`, `cancelled`, ... (`ContractState`) |
| `provider` | `stripe`, `paypal`, `mollie` |
| `contractId` | the contract id (not a credential; the contract token never leaves the start answer) |
| `updatedAt` | when the contract last changed (`DateTime`) |

- `paymentContract` is `null` for an order without a contract (placed through the Twig flow or with a core payment).
- Which orders a caller may read is the storefront's business: the list hangs on `customer { orders }`, the caller's own.
  The extension adds a field to what the storefront already shows; it exposes no provider ids and no payloads.
- The relation's namespace is mapped (`NamespaceMapper::getTypeNamespaceMapping()`) only when graphql-storefront is installed;
  without it the module activates and the schema builds without the field.
- Each order costs one contract lookup (`ContractSummaryQuery`, by `OXORDERID`, indexed). Page long order lists.
- After installing or updating the module run `bin/oe-console oe:graphql:cache-clear`, or the schema cache will not show the field.

## Guest checkout: no account, an anonymous token

A shopper without an account checks out with the token of `query { token }` (no credentials; group `oxidanonymous`).
payment-base grants that group the storefront's basket rights (`CREATE_BASKET`, `VIEW_BASKET`, `ADD_PRODUCT_TO_BASKET`,
`REMOVE_BASKET_PRODUCT`, `ADD_VOUCHER`, `REMOVE_VOUCHER`) and its own `PAYMENT_CHECKOUT` and `PAYMENT_CLIENT_CONFIG`, not
`PLACE_ORDER`. What the spike of 2026-10-22 on the SDK shop showed:

| Call with an anonymous token | Result |
|---|---|
| `basketCreate`, `basketAddItem`, `basket` | work; the basket row has **no owner** (`OXUSERID` empty, `owner: null`) |
| the basket id used by another anonymous token | readable and writable (the 128-bit id is the capability) |
| a logged-in user's basket | refused ("belongs to somebody else") |
| `basketSetPayment`, `basketSetDeliveryMethod`, `basketPayments`, `basketDeliveryMethods`, `basketSetDeliveryAddress` | `@Logged`: not in the anonymous schema |
| `placeOrder` | refused (no `PLACE_ORDER`; and refused for contract-first payments anyway) |

So the basket is built anonymously, and the checkout start carries what the logged-in calls would have set:

1. The provider's start mutation sends `guest` (`GuestDetails`: `email`, `invoiceAddress`, optional `deliveryAddress`; an
   address is `salutation?`, `firstName`, `lastName`, `company?`, `additionalInfo?`, `street`, `streetNumber`, `zipCode`,
   `city`, `countryId`, `stateId?`, `phone?`, named like the storefront's address inputs) and the payment it stands for.
   The guest details are read only when the token's user does not exist in the shop; for a logged-in user they are ignored.
2. `HeadlessCheckoutService::start()` validates the request first (return URLs, basket, payment, terms), then asks
   `GuestUserServiceInterface` for the user: a new passwordless account, or the existing guest account of that e-mail with
   the addresses of this checkout. An e-mail of a registered account (it has a password) is refused with `account_exists`
   and changes nothing; the shopper logs in. Missing or invalid details are `guest_details_required` (the message names
   the field, never the value). An anonymous token without details is `guest_details_required` too.
3. `GuestBasketClaimInterface` makes the guest the owner of the ownerless basket (and records the delivery address
   in `OEGQL_DELADDRESSID` where the storefront has the column). A basket that already has another owner is refused as
   `basket_forbidden` and not touched.
4. The checkout continues as for a logged-in user, with the guest as the shop user (contract, order, token, return and
   cancel are unchanged). A second start for the same basket with the same e-mail reuses the account.

The `guest` argument is ONE input type, defined in payment-base (`GraphQL\DataType\GuestCheckoutInputFactory`) and used
by every provider's start mutation, so the schema holds a single `GuestCheckoutInput` however many providers are active:

```graphql
input GuestCheckoutInput { email: String!, invoiceAddress: GuestAddressInput!, deliveryAddress: GuestAddressInput }
input GuestAddressInput  { firstName: String!, lastName: String!, street: String!, streetNumber: String!, zipCode: String!,
                           city: String!, countryId: ID!, salutation: String, company: String, additionalInfo: String,
                           stateId: ID, phone: String }
```

The input builds the validated `GuestDetails` / `GuestAddress` value objects. Their refusals (`guest_details_required`,
naming the field) happen while GraphQLite resolves the arguments, before the provider's controller runs, so they leave
as `GraphQL\Exception\GuestDetailsRefused`: a graphql-base error with `extensions.errorCode` like every other refusal.
A provider module's start mutation just declares `?GuestDetails $guest = null`; it defines no input type of its own.

Not part of this: creating a password-protected account after checkout, newsletter opt-in, B2B fields. A delivery address
different from the invoice address is stored for the guest; honouring a basket's delivery address in the order itself is
the same gap as for logged-in baskets (see the CHANGELOG).

## Trying it

Every provider module ships `bin/graph-ql-cli-test.sh` with the same commands (`schema`, `start`, `pay`, `return`,
`cancel`, `wrong-token`, `guard`, `demo`, `raw`) and a `bin/graph-ql-cli-test.md` with the provider's specifics:

- Stripe: `stripe/bin/graph-ql-cli-test.md`
- Mollie: `mollie-payment/bin/graph-ql-cli-test.md`
- PayPal: `paypal/bin/graph-ql-cli-test.md`

Two things curl-level clients trip over: OXID empties the basket for user agents it takes for search engines (curl's
default is one — send a browser-like `User-Agent`), and the storefront hides `#[Logged]` fields (the `basketSet*`
mutations and the provider mutations) from anonymous introspection — log in first.

## For a new provider module

The checklist is in `dev_log/20261006/done/sprint-15-S8-gates-consumers-handover.md`; the Mollie story
(`mollie-payment/docs/dev_day_log/20261006/`) is the most complete template. One rule costs the most when forgotten:
the GraphQL glue registered in `services.yaml` (namespace mapper, permission provider) must **mirror** graphql-base's
interfaces without implementing them — module activation compiles the container, which reflects every service class,
and on a shop without GraphQL the interface does not exist.
