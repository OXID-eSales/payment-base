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
  changes nothing (`invalid_token`).
- `returnUrl` / `cancelUrl` belong to the client and must be under the shop's own URL or an origin listed in the setting
  `sPaymentBaseHeadlessReturnOrigins` (`return_url_rejected / origin_not_allowed` otherwise). Every provider appends
  `contract_id` to the return URL; Stripe adds `session_id`, PayPal `token` and `PayerID`, Mollie nothing.
- Refusals are GraphQL errors with a stable `extensions.errorCode` (`HeadlessCheckoutException`): `basket_not_found`,
  `payment_not_supported`, `terms_not_confirmed`, `return_url_rejected`, `user_not_found`, `provider_failed`
  (+ `providerCode`, e.g. `MOLLIE_UI_MODE_UNSUPPORTED`), `contract_not_found`, `invalid_token`, `no_return_resolver`.
- A second start for the same basket retires the first attempt (one open attempt per user basket).
- The headless keys of the request (`basketId`, `uiMode`, `headless`, `sessionId`) cannot be overridden by a provider's
  options (`HeadlessStartRequest::$providerOptions`, used for Mollie's `method`).

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
