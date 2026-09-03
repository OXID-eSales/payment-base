<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Model;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use OxidEsales\PaymentBase\Service\VoucherReleaseSettingsInterface;
use Throwable;

/**
 * Sprint 09 — an order that ends returns its vouchers to the pool.
 *
 * A coupon is stamped as spent when the order is created. Core never lifts that
 * stamp: cancelOrder() sets oxstorno, saves and calls cancelOrderArticle() on
 * each line — so stock comes back — and delete() removes the order articles and
 * the payment row. Neither mentions vouchers. The customer's coupon is gone,
 * for an order that was called off or no longer exists.
 *
 * Delete is the worse case. The link is `oxvouchers.OXORDERID`, and every
 * release path looks a voucher up BY its order, so once the order row is gone
 * the voucher is an orphan no action can heal — not by hand, not by the
 * cleanup command. Hence the ordering below.
 *
 * This class only decides WHEN. The reset itself lives once, in
 * {@see VoucherReleaseInterface}, which is also what the cleanup command and
 * the checkout-retry cleanup call.
 *
 * @since 2026-09-03
 */
class Order extends Order_parent
{
    /**
     * @param string|null $sOxId
     *
     * @return bool
     */
    public function delete($sOxId = null)
    {
        // BEFORE the row goes: afterwards the voucher points at an order that
        // is not in oxorder any more and can never be matched again.
        $this->releaseVouchersOfThisOrder($sOxId);

        return (bool) parent::delete($sOxId);
    }

    public function cancelOrder(): void
    {
        parent::cancelOrder();

        // After, and only after: core restocks the articles only when its own
        // save() succeeded, and the coupon follows that same signal rather than
        // inventing a second notion of "the storno took".
        $this->releaseVouchersOfThisOrder();
    }

    /**
     * Best-effort by design. A coupon that failed to come back is a support
     * ticket; an admin action that throws is an outage.
     */
    private function releaseVouchersOfThisOrder(?string $orderId = null): void
    {
        try {
            if (!$this->getVoucherReleaseSettings()->isReleaseOnOrderEndEnabled()) {
                return;
            }

            $id = $orderId ?? (string) $this->getId();
            if ($id === '') {
                return;
            }

            $this->getVoucherRelease()->releaseVouchers($id);
        } catch (Throwable) {
            // Intentionally swallowed — see the note above. The repository
            // logs the SQL failure itself.
        }
    }

    protected function getVoucherRelease(): VoucherReleaseInterface
    {
        /** @var VoucherReleaseInterface $release */
        $release = ContainerFactory::getInstance()
            ->getContainer()
            ->get(VoucherReleaseInterface::class);

        return $release;
    }

    protected function getVoucherReleaseSettings(): VoucherReleaseSettingsInterface
    {
        /** @var VoucherReleaseSettingsInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(VoucherReleaseSettingsInterface::class);

        return $settings;
    }
}
