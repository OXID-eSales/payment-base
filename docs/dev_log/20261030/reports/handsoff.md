# Hand-off — violations review, 2026-10-30

Four violations reports, one per module, in `reports/`:
- `violations-review-payment-base.md`
- `violations-review-stripe.md`
- `violations-review-mollie.md`
- `violations-review-paypal.md`

Each report has the same sections: security, design/code violations (SOLID, DRY/agnostic boundary, TDD, Clean Code), a short list of low-importance items, server logs (pay1), and next steps. One or two of the most serious findings per module were re-read in the code and hold; the rest come from the review agents and were not re-checked one by one.

Code state reviewed (all `b-7.4.x`): payment-base `6fc1f0a`, stripe `b4ae8b0`, mollie-payment `5f9fc0e`, paypal `8538946`.

## Live problem on pay1

Three payment-base database migrations have not run on pay1 (checked with a read-only query on 2026-10-30): the new mail-state column `OXORDERMAILSTATE` is missing, and the last recorded migration in `oxmigrations_payment_base` is `Version20260924120000`.

- Since 10-28, every order on pay1 sends its confirmation mail before payment.
- Each of those failures writes the full customer mail (name, address, e-mail) into `oxideshop.log`, about 105k lines so far.
- **Fix:** run `vendor/bin/oe-payment-base-migrate` on pay1. Not run yet; this needs the owner's go.

## Errors in oxideshop.log (10-12 to 10-30)

1,250 errors, 1,792 warnings, no notices. All the recent errors from our modules were 500s caused by deploys, not by bugs in the payment logic:

- **Stripe, 10-30:** webhooks returned 500 for about 2 hours and the checkout payment list for about 70 minutes, because the service cache (`var/cache/container`) was not rebuilt after the deploy.
- **Whole shop, 10-30 02:45:** a fatal error, because Stripe and Mollie were deployed before the payment-base version they need.
- **Mollie, 10-15:** about 3 minutes of 500s from the same stale cache.
- **PayPal:** nothing.

## payment-base (6 High / 16 Medium / 15 Low)

- If a webhook handler crashes with a PHP error rather than an exception, the event stays "claimed" for good. Every retry is then answered "already processed", so a paid order can stay unfinished (confirmed in code).
- The customer-return path commits the order without checking the paid amount or currency; only the webhook path checks.
- Building a contract still falls back to EUR and to a 0.00 total (confirmed in code).
- Capture and refund limits are written separately in all three provider modules instead of once in payment-base.
- The classic admin can capture and refund without the payment rights, MFA and duplicate-click protection the admin app has.

## Stripe (3 High / 13 Medium / 15 Low)

- **Paid PaymentIntent from another order can be reused.** An old return endpoint (`stripeReturn` / `executeStripePayment`) takes the PaymentIntent id from the URL and marks the order paid. It does not check that the payment belongs to this checkout or covers the amount. Nothing calls this endpoint any more, but it can still be reached.
- **Small refunds restock the whole order.** Any refund, even one cent, puts back stock for every order line and cancels them all. A test pins this behaviour.
- **Webhook log:**
  - 420 signature failures come from three stale Stripe endpoints on one account.
  - pay1 has never received the "checkout completed" or "amount capturable" events.
  - Cancel, fail and refund events were never applied in 3 months, and the log does not say why.

## Mollie (5 High / 19 Medium / 15 Low)

- **No duplicate protection on capture/refund.** The duplicate-protection key is never sent to Mollie (confirmed: nothing in the module calls `setIdempotencyKey`). A double click or retry can refund or capture twice.
- **Small refunds restock the whole order**, the same bug as Stripe.
- **Paid but no order.** A payment that arrives after its contract was cancelled has nowhere to go: the money is taken, there is no order, and nothing refunds it.
- **Tokens in the shop log.** When creating a payment fails, the module logs the full request, including the return token and card token.
- **Webhook log:** no failures and no secrets, but the lines carry no payment id or skip reason, so nothing can be traced.

## PayPal (6 High / 17 Medium / 15 Low)

- **Thank-you page of another shopper.** The return page accepts any contract id from the URL with no owner or token check. Someone with a leaked id can push another shopper's order through and see it. The class meant to prevent this is never called, and its tests only test that unused class.
- **Failed webhooks are lost.** A failed webhook is never retried because the claim stays in place; payment-base already fixed this for Stripe and Mollie.
- **Refunds counted twice.** Every admin refund is booked twice, once by the admin action and again by the refund webhook.
- **No webhook log.** PayPal writes no webhook log on pay1; the report recommends adding one like Stripe's and Mollie's.

## Duplicated across providers

- **Webhook security checks:** copied in all three providers.
- **Contract and refund helpers:** stock restore, refund booking and contract cancel/fail are copied in Stripe and Mollie.
- All of these belong in payment-base.

## Open actions

1. Run `vendor/bin/oe-payment-base-migrate` on pay1, then confirm the `OXORDERMAILSTATE` column exists.
2. Fix the deploy order: payment-base first, then the providers, then rebuild the service cache, then let traffic in.
3. In the Stripe Dashboard, delete the three stale webhook endpoints and update the pay1 endpoint with the full event list.
4. Code fixes, in order:
   - Mollie: send the duplicate-protection key on capture/refund.
   - PayPal: add the owner/token check on the return page.
   - payment-base: a crashing webhook must leave the event retryable.
   - Stripe and Mollie: restock only on a full refund.
