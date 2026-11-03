# Sprint 24 — VR-HARDEN: every entry point checks CSRF, consent and rights; secrets never travel in URLs

**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-24-harden` in payment-base, stripe, paypal (mollie only if R/L items left over).
**Status:** PLANNED · **Priority:** P2 (medium findings, not exploitable without extra conditions).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md).
**Sources:** paypal SEC-6, Low (approval-URL host allow-list, `sDeliveryAddressMD5`, `|raw` hrefs, IPv6, OAuth token
per delivery, Connect nonce fallback); stripe S-5, Low (`render()` side effects); payment-base S7, S8, D5, D6 (if not in
sprint 18), Low (`ValidationApiController::init`, `HeadlessCheckoutService` order of checks, guest overwrite, thankyou
`notice|raw`).

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| H1 | paypal | **CSRF + consent on all front entry points (SEC-6)** | `PayPalOrderControllerTest::executeRequiresStoken`, `…createOrderJsonRequiresStoken`, `…paypalCheckoutStartRequiresStoken`; `…redirectModeAppliesTermsGate`; `paypal_footer` template test: no hidden `ord_agb=1`/`ord_custinfo=1`/product agreements | `checkSessionChallenge()` in all three; `TermsGate` in every UX mode; render the real agreement checkboxes | Playwright: PayPal redirect + Smart Buttons without AGB tick ⇒ blocked (same as MOL-11/MOL-9 for Mollie); cross-site POST (Playwright `request` from another origin) ⇒ refused |
| H2 | paypal | Redirect + transport hygiene | `PayPalOrderControllerTest::approvalUrlOutsidePayPalHostsRejected`; `PayPalWebhookSignatureVerifierTest::accessTokenCachedUntilExpiry`; `IpAllowlistGuard` IPv6 (moves to sprint 19 W2 if done there) | reuse `PayerActionUrlValidator`; token cache; pass `sDeliveryAddressMD5` explicitly instead of writing `$_POST` | sandbox e2e green |
| H3 | paypal | Admin Connect | `PayPalConnectTest::referralFailureShowsErrorNoBareSignupUrl`; `…authAfterWebLoginErrorIsJson`; template `|e('html_attr')` | remove the "degraded fallback"; JSON errors | Playwright admin Connect with a forced referral failure |
| H4 | stripe | Connect callback without a secret in the URL (S-5) | `StripeConnectTest::rejectsGetWithAccessToken`; `…requiresPaymentConfigRight`; `…validatesKeyPrefixAgainstMode` | POST/one-time code exchange; `PaymentRightsProvider::CONFIG` | Playwright admin Stripe Connect onboarding (test mode) |
| H5 | stripe | `render()` without side effects | `StripeOrderControllerTest::renderDoesNotRotateSessChallenge` | move session cleanup to the actions that need it | Stripe e2e green |
| H6 | payment-base | Provider knowledge out of the core (D5, D6 rest) | `PaymentOwnershipSubscriberTest` with a fake 4th provider registered via tag; `ClientConfigGuardTest` with patterns contributed by provider tags | tagged `PaymentOwnershipProviderInterface`, `ClientConfigSecretPatternProvider`; providers register theirs | grep in `payment-base/src`: no `stripe`/`mollie`/`paypal` literal outside comments (CI check, joins DC-5 in sprint 17) |
| H7 | payment-base | Small hardening | `ValidationApiControllerTest::initFailureIsFatal`; `HeadlessCheckoutServiceTest::ownershipCheckedBeforePaymentLookup`; `OxidGuestUserServiceTest` (decide core parity vs refuse — owner decision recorded) | fixes | — |
| H8 | payment-base | MCP/ACP surface (S7, S8) | only if sprint 17 keeps `src/Mcp` (owner decision): `AbstractAcpCheckoutServiceTest::foreignAgentCannotReadContract`; `JwtTokenValidatorTest::forgedSignatureRejected` | ownership guard + JWKS verification | — (deleted in sprint 17 otherwise) |

## Definition of done

All Medium security findings of the four reports are closed or explicitly moved (with reason) to the owner's
backlog. Reports in `done/`.
