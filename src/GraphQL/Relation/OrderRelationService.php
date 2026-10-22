<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Relation;

use OxidEsales\GraphQL\Storefront\Order\DataType\Order;
use OxidEsales\PaymentBase\GraphQL\DataType\PaymentContractSummaryType;
use OxidEsales\PaymentBase\Repository\ContractSummaryQueryInterface;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;

/**
 * Adds `paymentContract` to graphql-storefront's `Order` type, so a headless
 * order list shows the payment state without a second channel. The storefront
 * decides which orders a caller may read; this only adds a field to them.
 *
 * Mapped by NamespaceMapper only when graphql-storefront is installed; never
 * implements or extends anything of the optional packages (the container
 * reflects the class at module activation).
 *
 * @since 1.4.0
 */
#[ExtendType(class: Order::class)]
final class OrderRelationService
{
    public function __construct(private readonly ContractSummaryQueryInterface $summaries)
    {
    }

    #[Field]
    public function paymentContract(Order $order): ?PaymentContractSummaryType
    {
        $summary = $this->summaries->findByOrderId((string) $order->getEshopModel()->getId());

        return $summary === null ? null : new PaymentContractSummaryType($summary);
    }
}
