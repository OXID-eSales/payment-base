<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Request;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Registry;

/**
 * Request DTO for creating an order.
 *
 * Contains all necessary information to create and finalize an order
 * from the current basket/cart.
 *
 * @since 1.0.0
 */
final readonly class CreateOrderRequest
{
    /**
     * @param string $sessionId Session identifier for retrieving basket
     * @param string $userId User/Customer identifier
     * @param string $paymentId Payment method identifier
     * @param string|null $paymentTransactionId External payment transaction ID (e.g., PaymentIntent ID)
     * @param string|null $orderRemark Customer's order remark/comment
     * @param array<string, mixed> $metadata Additional metadata to store with order
     */
    public function __construct(
        public string $sessionId,
        public string $userId,
        public string $paymentId,
        public ?string $paymentTransactionId = null,
        public ?string $orderRemark = null,
        public array $metadata = []
    ) {
    }

    /**
     * Get the basket from session.
     *
     * @return Basket|null
     */
    public function getBasket(): ?Basket
    {
        return Registry::getSession()->getBasket();
    }
}
