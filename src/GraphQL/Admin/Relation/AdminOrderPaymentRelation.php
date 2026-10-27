<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Relation;

use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrder;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReaderInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * Adds `paymentContract` to the admin API's `AdminOrder`: the payment contract of the order with its state,
 * amounts and transactions, for every provider. Which orders the caller may read is the admin API's decision;
 * this only adds a field to them, behind its own right (ADMIN_PAYMENT_VIEW). Null for an order that was not paid
 * through a provider module (invoice, cash on delivery, ...).
 *
 * Mapped by NamespaceMapper only when the admin API is installed; never implements or extends anything of the
 * optional package (the container reflects the class at module activation).
 *
 * @since 1.5.0
 */
#[ExtendType(class: AdminOrder::class)]
final class AdminOrderPaymentRelation
{
    public function __construct(private readonly PaymentContractDetailsReaderInterface $details)
    {
    }

    #[Field]
    #[Right(PaymentRightsProvider::VIEW)]
    public function paymentContract(AdminOrder $order): ?AdminPaymentContract
    {
        $payment = $this->details->findByOrderId($order->id);

        return $payment === null ? null : new AdminPaymentContract($payment);
    }
}
