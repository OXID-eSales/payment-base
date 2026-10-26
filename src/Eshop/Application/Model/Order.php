<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Model;

use OxidEsales\Eshop\Core\Email as ShopEmail;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\DefersConfirmationMailInterface;
use OxidEsales\PaymentBase\Mail\OrderMailCaptureInterface;
use OxidEsales\PaymentBase\Repository\DeferredOrderMailRepositoryInterface;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use OxidEsales\PaymentBase\Service\VoucherReleaseSettingsInterface;
use Psr\Log\LoggerInterface;
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
 * STRP-176 — the order mail of a PSP checkout waits for the payment. Early
 * order creation runs finalizeOrder() before the customer pays at the PSP, and
 * core ends finalizeOrder() by mailing "thank you for your order". When the
 * order is deferred, core still renders both mails, on a capturing mailer, and
 * they are stored until the contract commits. Stored mails go with the order
 * when it is deleted or cancelled.
 *
 * @since 2026-09-03
 */
class Order extends Order_parent implements DefersConfirmationMailInterface
{
    private bool $confirmationMailDeferred = false;

    /**
     * Called by the early order creation of payment-base, before finalizeOrder().
     * Lives on this object only: nothing else that finalizes an order changes.
     */
    public function deferConfirmationMail(): void
    {
        $this->confirmationMailDeferred = true;
    }

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

        $deleted = (bool) parent::delete($sOxId);
        if ($deleted) {
            $this->dropDeferredOrderMails($sOxId);
        }

        return $deleted;
    }

    /**
     * Deliberately without a native return type, because the shop's own
     * `Order::cancelOrder()` declares none.
     *
     * A module extension sits in a chain, not on top of the core alone: every
     * other module extending the same method becomes this class's parent or
     * child depending on activation order. PHP allows a child to ADD a return
     * type where the parent declares none, but not to replace one — so the
     * first extension in the chain that writes a type takes that freedom away
     * from everyone below it, and the shop fatals at compile time with
     * "Declaration of … must be compatible with …". Measured against
     * opal/opalsubscription, which declares `mixed` here: with both modules
     * active the storefront checkout went blank.
     *
     * Keeping the core's shape is therefore not a style choice but the only
     * signature that leaves the chain usable. The `@return` below is what tells
     * static analysis what comes back — `mixed` rather than `void` for the same
     * reason: it is what the untyped core resolves to, and it commits nobody.
     *
     * @return mixed whatever the shop returns, which today is nothing
     */
    public function cancelOrder()
    {
        parent::cancelOrder();

        // After, and only after: core restocks the articles only when its own
        // save() succeeded, and the coupon follows that same signal rather than
        // inventing a second notion of "the storno took".
        $this->releaseVouchersOfThisOrder();

        // A cancelled order is never confirmed; its mail must not go out later.
        $this->dropDeferredOrderMails();
    }

    /**
     * Core's signature, untyped, for the chain reason cancelOrder() gives.
     *
     * @param mixed $oUser
     * @param mixed $oBasket
     * @param mixed $oPayment
     *
     * @return int
     */
    protected function sendOrderByEmail($oUser = null, $oBasket = null, $oPayment = null)
    {
        if (!$this->confirmationMailDeferred) {
            return parent::sendOrderByEmail($oUser, $oBasket, $oPayment);
        }

        $mailer = $this->newOrderMailer();
        if ($mailer === null) {
            // Without the Email extension nothing can keep the mail, and nothing
            // would send it later: an early mail beats none.
            $this->getOrderMailLogger()->warning(
                '[STRP-176] Email extension missing from the chain, order mail sent at order creation',
                ['orderId' => (string) $this->getId()]
            );

            return parent::sendOrderByEmail($oUser, $oBasket, $oPayment);
        }

        return $this->storeOrderMails($mailer, $oUser, $oBasket, $oPayment);
    }

    /**
     * What core's sendOrderByEmail() does - the three assignments the mail
     * templates read, then the customer and the owner mail - but on a mailer
     * that keeps the mails. Repeated rather than reused because core creates
     * its Email inside the method and offers no way to hand it another.
     */
    private function storeOrderMails(OrderMailCaptureInterface $mailer, mixed $user, mixed $basket, mixed $payment): int
    {
        $this->_oUser = $user;
        $this->_oBasket = $basket;
        $this->_oPayment = $payment;

        $mailer->captureAs(DeferredOrderMail::KIND_CUSTOMER);
        $customerMailBuilt = (bool) $mailer->sendOrderEmailToUser($this);
        $mailer->captureAs(DeferredOrderMail::KIND_OWNER);
        $mailer->sendOrderEmailToOwner($this);

        try {
            $this->getDeferredOrderMails()->save(
                (string) $this->getId(),
                (int) $this->getShopId(),
                $mailer->takeCapturedMails()
            );
        } catch (Throwable $e) {
            $this->getOrderMailLogger()->error('[STRP-176] order mail could not be stored, it will not be sent', [
                'orderId' => (string) $this->getId(),
                'error' => $e->getMessage(),
            ]);

            return self::ORDER_STATE_MAILINGERROR;
        }

        return $customerMailBuilt ? self::ORDER_STATE_OK : self::ORDER_STATE_MAILINGERROR;
    }

    /**
     * Best-effort, like the voucher release: a stale row is a cleanup job, a
     * failing delete or storno is an outage.
     */
    private function dropDeferredOrderMails(?string $orderId = null): void
    {
        $id = $orderId ?? (string) $this->getId();
        if ($id === '') {
            return;
        }

        try {
            $this->getDeferredOrderMails()->deleteByOrderId($id);
        } catch (Throwable $e) {
            $this->getOrderMailLogger()->error('[STRP-176] stored order mails of an ended order not removed', [
                'orderId' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The end of the Email chain; null when this module's extension is not in
     * it (module chain not regenerated after activation).
     */
    protected function newOrderMailer(): ?OrderMailCaptureInterface
    {
        $mailer = oxNew(ShopEmail::class);

        return $mailer instanceof OrderMailCaptureInterface ? $mailer : null;
    }

    protected function getDeferredOrderMails(): DeferredOrderMailRepositoryInterface
    {
        /** @var DeferredOrderMailRepositoryInterface $mails */
        $mails = ContainerFactory::getInstance()
            ->getContainer()
            ->get(DeferredOrderMailRepositoryInterface::class);

        return $mails;
    }

    protected function getOrderMailLogger(): LoggerInterface
    {
        return Registry::getLogger();
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
