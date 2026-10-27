<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderChangeGuard;
use OxidEsales\GraphQL\Admin\Order\DataType\OrderChangeKind;
use OxidEsales\PaymentBase\Math\Money\Money;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Answers the admin API's AdminOrderChangeGuard for every provider: once a payment was captured, the amount
 * the customer paid must not drift away from the order, so a change that recalculates the total is refused
 * (items, payment method, delivery set). An address correction stays possible - the most common fix of
 * an admin - because it moves no money.
 *
 * An order without a contract is not ours and is left alone. The event class belongs to the optional
 * oxid-admin-graphql; the subscriber is only ever called when that module dispatches it.
 *
 * @since 1.5.0
 */
final class OrderChangeGuardSubscriber implements EventSubscriberInterface
{
    private const REFUSAL = 'The payment of this order was already captured, so its items and totals can no longer'
        . ' change; refund the payment instead.';

    public function __construct(private readonly ContractRepositoryInterface $contracts)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [AdminOrderChangeGuard::class => 'onChangeGuard'];
    }

    public function onChangeGuard(AdminOrderChangeGuard $guard): void
    {
        if ($guard->change === OrderChangeKind::Addresses) {
            return;
        }
        $contract = $this->contracts->findByOrderId($guard->orderId);
        if ($contract === null || !Money::greaterThan($contract->getCapturedAmount() ?? 0.0, 0.0)) {
            return;
        }
        $guard->refuse(self::REFUSAL);
    }
}
