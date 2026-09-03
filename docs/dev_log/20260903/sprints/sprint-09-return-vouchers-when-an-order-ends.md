# Sprint 09 — Return the voucher to the pool when an order ends

**Branch:** `b-7.4.x` (payment-base only)
**Module:** `payment-base` — the rule is shop-level order behaviour, not a PSP concern. No PSP module is touched.
**Engineering requirements:** [`_engeneering_requirements.md`](./_engeneering_requirements.md) — binding for every story below.
**Estimated size:** ~120 LOC production, ~320 LOC tests, 1 new class extension, 1 new module setting.

---

## 1. Why

A coupon applied to an order is stamped as spent the moment the order is
created. If that order later **ends** — cancelled (storno) or deleted by the
merchant in the admin — the stamp is never lifted. The customer's coupon is
gone, for an order that no longer exists or was explicitly called off.

For a **deleted** order it is worse than a lost coupon: the `oxvouchers` row
still carries the `OXORDERID` of an order that is no longer in `oxorder`. No
later action can find it, because every release path we have looks the voucher
up *by its order*. It is an orphan the shop cannot heal, by hand or by command.

## 2. What already works, and what does not

`releaseVouchers()` exists and is correct. It resets everything early order
creation stamped — `OXORDERID`, `OXUSERID`, `OXDISCOUNT`, `OXDATEUSED`,
`OXRESERVED` — which is exactly "back in the pool".

| Path that ends an order | Releases the voucher? | Where |
|---|---|---|
| `oe:payments:not_finished:cleanup` (the command) | ✅ yes | `NotFinishedOrderCleanupService::cleanup()` |
| Checkout retry / return-without-paying | ✅ yes | `OxidShopOrderService::deleteNotFinishedOrder()` |
| **Admin → order → cancel (storno)** | ❌ **no** | core `Order::cancelOrder()` |
| **Admin → order → delete** | ❌ **no** | core `Order::delete()` |

Verified in core (`source/Application/Model/Order.php`):

- `cancelOrder()` sets `oxstorno = 1`, saves, and calls
  `cancelOrderArticle()` on each line — so **stock** is returned. Vouchers are
  not mentioned.
- `delete()` deletes the order articles and the payment row, then
  `parent::delete()`. Vouchers are not mentioned.

So core already has the *idea* of giving inventory back on cancel. The coupon
is simply missing from that idea, and this sprint adds it.

## 3. Scope

**In:** admin storno, admin delete, and a regression that pins the two paths
that already work so they cannot silently regress.

**Out:** refunds. A refunded order has not ended — it stays a real order — and
returning its coupon is a merchant policy decision, not a data-integrity fix.
See §8.

## 4. Design

One new class extension, no new SQL.

```
payment-base/src/Eshop/Application/Model/Order.php   (extends Order_parent)
  ├─ delete($sOxId = null)   → release FIRST, then parent::delete()
  └─ cancelOrder()           → parent::cancelOrder(), then release
```

- The extension **decides when**; `VoucherReleaseInterface::releaseVouchers()`
  **does the work**. No voucher SQL in the model.
- Resolved through `ContainerFactory` — OXID models get no constructor DI.
  `VoucherReleaseInterface` is already `public: true` in `services.yaml`, so the
  runtime lookup works. That is not incidental: a private id would be inlined
  away and the lookup would fail *silently*, inside the try/catch D4 asks for —
  a release that never happens and never complains.
- Registered in `metadata.php` `extend`, joining the existing
  `PriceList` / `PaymentController` / `OrderController` / `ThankYouController`
  entries.

### Decisions

| # | Decision | Reason |
|---|---|---|
| **D1** | On `delete()`, release **before** `parent::delete()`. | The link is `oxvouchers.OXORDERID`. After the order row is gone the voucher can never be found again — this ordering is the whole fix for the orphan case. |
| **D2** | On `cancelOrder()`, release **after** `parent::cancelOrder()`. | Core only restocks if `save()` succeeded. We follow the same signal: no storno, no release. |
| **D3** | A **paid** order that is stornoed also returns its coupon. | The customer is not receiving the goods. Withholding the coupon punishes them for the merchant's cancellation. |
| **D4** | Best-effort: a failed release is logged, never thrown. | A merchant must always be able to cancel or delete an order. A coupon that failed to return is a support ticket; a dead admin button is an outage. |
| **D5** | Idempotent by construction. | The statement is `WHERE OXORDERID = :id`; a second run matches nothing and returns 0. No "already released" flag is needed. |
| **D6** | Kill switch `blPaymentBaseReleaseVouchersOnOrderEnd`, default **on**. | Additive-only rule. A shop whose coupons are single-use-per-customer by policy can turn it off without patching. |
| **D7** | No shop-id scoping. | `OXORDERID` is globally unique; adding a shop filter would be noise, and the existing primitive does not do it either. |

## 5. Stories

One dispatch per story; do not collapse. Each ends green on all gates.

### S1 — Characterization first (RED)
Integration tests that create an order with a voucher, then storno it and
delete it, asserting **today's** behaviour: the `oxvouchers` row is still
stamped. These must fail after S2/S3 and be inverted there — that is the point.
Per the requirements, no production line is written before S1 is red.

### S2 — Release on delete
The `Order` extension with `delete()` only. D1 ordering is the assertion:
a test that deletes an order and then finds the voucher free.

### S3 — Release on storno
Add `cancelOrder()`. Covers D2 (no release when the save fails) and D3 (a paid,
stornoed order releases too).

### S4 — Kill switch
`blPaymentBaseReleaseVouchersOnOrderEnd` in `metadata.php`, read through
`ModuleSettingServiceInterface`, plus `SHOP_MODULE_GROUP_*` / `SHOP_MODULE_*`
language keys in **every** admin language file. Default on; off restores
byte-identical core behaviour.

### S5 — Pin the paths that already work
Regression tests for `NotFinishedOrderCleanupService` and
`deleteNotFinishedOrder()`. They release today; nothing in this sprint may
change that, and a future refactor must fail loudly if it does.

### S6 — Prove it through the admin UI  ⚠️ NOT DONE
Playwright, per the binding requirement: place an order with a real coupon,
storno it in the admin, and assert the **same coupon can be applied again** in a
fresh checkout. Then the same for delete. Re-applying is the only assertion that
discriminates — the voucher row looking tidy proves nothing about reusability.
Run the control: revert the extension, watch both fail.

**Attempted and withdrawn.** A spec was written and made green, but it also
passed with the feature switched **off** — it proved nothing, so it was not
committed. Two distinct reasons, both worth knowing before the next attempt:

1. **The PSP round-trip frees the coupon by itself.** Checking out through
   Mollie and coming back without paying is a release trigger in its own right
   (STRP-171 retires the abandoned attempt). Whatever the storno does or does
   not do, the coupon is already back. Use a payment that completes in-shop —
   invoice — so the storno is the only candidate.
2. **The admin list's first storno link is not the order you just placed.** The
   spec clicked `a[href^="Javascript:StornoThisArticle"]` first-match and
   cancelled an unrelated older order while asserting about the new one. The row
   has to be selected by its order number.

Until that spec exists and fails with the feature off, this story is open. What
*is* verified is in §6 below, and it is not a substitute.

### S7 — Record the invariant
`docs/invariants/` gains: *an order that ends returns its vouchers*, naming the
four paths and the single seam they share.

## 6a. Verified so far

Direct A/B against the real shop, driving `oxNew(Order)` — which resolves to
this extension — with the kill switch as the only variable:

| action | switch | result |
|---|---|---|
| `cancelOrder()` | off | voucher stays stamped (`OXORDERID`, `OXDATEUSED` set) |
| `cancelOrder()` | on | `OXORDERID`, `OXUSERID`, `OXDATEUSED`, `OXRESERVED` all cleared |
| `delete()` | on | order row gone **and** voucher cleared — which can only happen if the release ran first (D1) |

Plus 8 unit tests covering both orderings, the kill switch and the best-effort
paths. This is a real control, but it is not the UI proof S6 asks for.

## 6. Definition of Done

1. Admin storno and admin delete both return the coupon to the pool.
2. The coupon is **re-applicable** in a new basket afterwards — proven in the UI, not inferred from the row.
3. Deleting an order leaves no voucher row pointing at a vanished order.
4. The two existing paths still release, pinned by S5.
5. Kill switch off ⇒ core behaviour, byte-identical.
6. All gates green: `phpcs`, `phpstan` (level max, no new baseline entries), `phpmd`, Unit + Integration.
7. Consumer test counts in stripe / mollie / paypal / one-page-checkout unchanged.

## 7. Risks

| Risk | Handling |
|---|---|
| Another module already extends `Order` (Stripe does). | Extend `Order_parent` and call `parent::` first — the chain is preserved. A test asserts the chain still reaches core. |
| A voucher series that is genuinely single-use per customer. | D6 kill switch. |
| Releasing a coupon on an order the merchant stornoed for fraud. | Accepted, and documented: the merchant can still delete the voucher series entry by hand. Data integrity beats a guess about intent. |
| `ContainerFactory` unavailable during a CLI delete. | Best-effort (D4): log and continue, exactly as the retry cleanup does. |

## 8. Out of scope

- **Refunds.** A refunded order still exists; whether its coupon returns is policy, not integrity.
- **Partial cancellation.** OXID cancels whole orders; per-line voucher maths is not a thing here.
- **Re-issuing an expired voucher.** If the series has since expired, the row returns to the pool and the expiry still applies. Correct, and not our decision to override.
