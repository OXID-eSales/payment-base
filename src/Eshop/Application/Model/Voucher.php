<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Model;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Exception\VoucherException;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptOrderInterface;
use Throwable;

/**
 * OPC-175 / S2a — the coupon stays in the basket while its checkout attempt is
 * open.
 *
 * Every provider creates the order BEFORE the customer pays, and core's
 * finalizeOrder() marks the basket vouchers as used by that order. The basket
 * still holds them, and the next recalculation checks them again: core's
 * isAvailable() refuses a row whose OXORDERID is set, and
 * Basket::calcVoucherDiscount() drops the coupon without a word. The next
 * attempt (another payment method, a retry) is then created without the
 * discount.
 *
 * A row that the session's own open attempt holds is the customer's coupon,
 * not someone else's. This extension accepts exactly that row. The retire of
 * the attempt (PreviousCheckoutAttemptCleaner) releases it before the next
 * order marks it again. Every other row keeps core's rules, and so does
 * everything when the open attempt cannot be read.
 *
 * Methods without a native return type: core declares none, and the first
 * extension in a module chain that adds one breaks every module below it
 * (see the note on Order::cancelOrder()).
 *
 * @since OPC-175
 */
class Voucher extends Voucher_parent
{
    /**
     * @return bool
     */
    protected function isAvailable()
    {
        if ($this->isHeldByTheOpenAttempt()) {
            return true;
        }

        return parent::isAvailable();
    }

    /**
     * Core counts the user's earlier uses of a "one per customer" series. The
     * open attempt's own row is not an earlier use: it is this one.
     *
     * @param object $oUser
     *
     * @return bool
     */
    protected function isAvailableInOtherOrder($oUser)
    {
        $openOrderId = $this->openAttemptOrderId();
        if ($openOrderId === null) {
            return parent::isAvailableInOtherOrder($oUser);
        }

        $userId = method_exists($oUser, 'getId') ? (string) $oUser->getId() : '';
        if ($this->seriesAllowsAnotherUse() || $this->countUsesOutsideOrder($userId, $openOrderId) === 0) {
            return true;
        }

        $this->refuseSecondUseOfSeries();
    }

    private function isHeldByTheOpenAttempt(): bool
    {
        $orderId = (string) $this->getFieldData('oxorderid');
        if ($orderId === '') {
            return false;
        }

        return $orderId === $this->openAttemptOrderId();
    }

    /**
     * Best-effort: when the attempt cannot be read, core's rules apply.
     */
    private function openAttemptOrderId(): ?string
    {
        try {
            return $this->getOpenCheckoutAttemptOrder()->orderId();
        } catch (Throwable) {
            return null;
        }
    }

    protected function getOpenCheckoutAttemptOrder(): OpenCheckoutAttemptOrderInterface
    {
        /** @var OpenCheckoutAttemptOrderInterface $openOrder */
        $openOrder = ContainerFactory::getInstance()
            ->getContainer()
            ->get(OpenCheckoutAttemptOrderInterface::class);

        return $openOrder;
    }

    protected function seriesAllowsAnotherUse(): bool
    {
        return (bool) $this->getSerie()->getFieldData('oxallowuseanother');
    }

    /**
     * Core's count from isAvailableInOtherOrder(), without the rows of the
     * excluded order.
     */
    protected function countUsesOutsideOrder(string $userId, string $excludedOrderId): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->getViewName() . '
                WHERE oxuserid = :userId AND oxvoucherserieid = :seriesId
                  AND ((oxorderid IS NOT NULL AND oxorderid != "") OR (oxdateused IS NOT NULL AND oxdateused != 0))
                  AND NOT (oxorderid <=> :excludedOrderId)';

        return (int) DatabaseProvider::getDb()->getOne($sql, [
            ':userId' => $userId,
            ':seriesId' => (string) $this->getFieldData('oxvoucherserieid'),
            ':excludedOrderId' => $excludedOrderId,
        ]);
    }

    /**
     * The same refusal core throws for a second use of the series.
     */
    protected function refuseSecondUseOfSeries(): never
    {
        // `new`, not oxNew(): an exception is not a module-extensible model.
        $exception = new VoucherException();
        $exception->setMessage('ERROR_MESSAGE_VOUCHER_NOTALLOWEDSAMESERIES');
        $exception->setVoucherNr((string) $this->getFieldData('oxvouchernr'));

        throw $exception;
    }
}
