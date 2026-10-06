<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Subscriber;

use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\GraphQL\Storefront\Basket\Event\BeforePlaceOrder;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistryInterface;
use OxidEsales\PaymentBase\GraphQL\Exception\ContractFirstPaymentCheckout;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Option B's guard: core `placeOrder` is not used for contract-first
 * payments. The storefront dispatches BeforePlaceOrder first thing; when the
 * basket's payment belongs to a provider module, the call is refused with the
 * mutation to use instead. A basket core cannot find, or a core payment,
 * passes untouched.
 *
 * @since 3.0.0
 */
class RefusePlaceOrderForContractFirstPayments implements EventSubscriberInterface
{
    private const STOREFRONT_PAYMENT_FIELD = 'oegql_paymentid';

    public function __construct(private readonly PaymentHandlerRegistryInterface $handlers)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [BeforePlaceOrder::class => 'onBeforePlaceOrder'];
    }

    public function onBeforePlaceOrder(BeforePlaceOrder $event): void
    {
        $row = $this->loadUserBasket((string) $event->getBasketId());
        if ($row === null) {
            return;
        }

        $paymentId = (string) $row->getFieldData(self::STOREFRONT_PAYMENT_FIELD);
        if ($paymentId === '') {
            return;
        }

        $handler = $this->handlers->forPaymentMethod($paymentId);
        if ($handler === null) {
            return;
        }

        throw new ContractFirstPaymentCheckout($paymentId, $handler->getId());
    }

    /**
     * Seam: the `oxuserbaskets` row.
     */
    protected function loadUserBasket(string $basketId): ?UserBasket
    {
        /** @var UserBasket $row */
        $row = oxNew(UserBasket::class);

        return $row->load($basketId) ? $row : null;
    }
}
