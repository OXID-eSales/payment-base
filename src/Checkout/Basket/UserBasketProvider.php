<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\DeliverySetList;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Service\DeliveryAddressHashServiceInterface;

/**
 * The headless basket source: an `oxuserbaskets` row becomes the calculated
 * shop Basket that Order::finalizeOrder() needs.
 *
 * This is what graphql-storefront's BasketInfrastructure::placeOrder() does
 * before it finalizes - user, items, payment, delivery set, calculate - so the
 * amount core puts on the order is the amount `basketPayments` showed the
 * shopper. Two things the storefront keeps in extra columns are read when
 * present and resolved otherwise: the delivery set (`OEGQL_DELIVERYMETHODID`;
 * without it, the first set the user may use with this basket, as the Twig
 * payment step picks it) and the payment, which always comes from the request
 * because the contract already names it.
 *
 * Ownership is checked here, before an article is loaded: the JWT user may
 * only check out their own basket.
 *
 * @since 3.0.0
 */
class UserBasketProvider implements CheckoutBasketProviderInterface
{
    public const ERROR_BASKET_FORBIDDEN = 'basket_forbidden';

    private const STOREFRONT_DELIVERY_SET_FIELD = 'oegql_deliverymethodid';

    public function __construct(
        private readonly DeliveryAddressHashServiceInterface $deliveryAddressHash
    ) {
    }

    public function basketFor(CreateOrderRequest $request): ?Basket
    {
        if ($request->basketId === null) {
            return null;
        }

        $row = $this->loadUserBasket($request->basketId);
        if ($row === null) {
            return null;
        }

        $this->refuseForeignBasket($row, $request);
        $user = $this->loadUser($request->userId);
        if ($user === null) {
            throw new ShopOrderException(
                message: 'User not found',
                errorCode: 'user_not_found',
                context: ['user_id' => $request->userId, 'basket_id' => $request->basketId]
            );
        }

        $basket = $this->buildBasket($row, $user, $request);

        // core's Order::validateDeliveryAddress() compares a request hash with
        // the user's current address; the Twig order page posts it, the
        // storefront sets it itself, and so do we - through the one service
        // that knows where core reads it.
        $this->deliveryAddressHash->restoreHashForValidation((string) $user->getEncodedDeliveryAddress());

        return $basket;
    }

    private function refuseForeignBasket(UserBasket $row, CreateOrderRequest $request): void
    {
        $ownerId = (string) $row->getFieldData('oxuserid');
        if ($ownerId === $request->userId) {
            return;
        }

        throw new ShopOrderException(
            message: 'Basket belongs to another user',
            errorCode: self::ERROR_BASKET_FORBIDDEN,
            context: ['basket_id' => $request->basketId, 'user_id' => $request->userId]
        );
    }

    private function buildBasket(UserBasket $row, User $user, CreateOrderRequest $request): Basket
    {
        $basket = $this->newBasket();
        $basket->setBasketUser($user);

        foreach ($row->getItems() as $item) {
            $basket->addToBasket(
                (string) $item->getFieldData('oxartid'),
                (float) $item->getFieldData('oxamount'),
                $item->getSelList(),
                $item->getPersParams()
            );
        }

        $basket->setPayment($request->paymentId);
        // Delivery-set resolution needs the basket's payment price, so the
        // first calculation happens before it and the second after.
        $basket->calculateBasket(true);
        $basket->setShipping($this->resolveDeliverySet($user, $basket, $this->storefrontDeliverySet($row)));
        $basket->calculateBasket(true);

        return $basket;
    }

    /**
     * graphql-storefront's `basketSetDeliveryMethod` choice, when the column
     * exists and was set. Checked with isset() so a shop without the
     * storefront (no column) never trips a missing-property notice.
     */
    private function storefrontDeliverySet(UserBasket $row): ?string
    {
        $property = 'oxuserbaskets__' . self::STOREFRONT_DELIVERY_SET_FIELD;
        if (!isset($row->$property)) {
            return null;
        }

        $value = $row->getFieldData(self::STOREFRONT_DELIVERY_SET_FIELD);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Seam: the `oxuserbaskets` row, or null when there is none.
     */
    protected function loadUserBasket(string $basketId): ?UserBasket
    {
        /** @var UserBasket $row */
        $row = oxNew(UserBasket::class);

        return $row->load($basketId) ? $row : null;
    }

    /**
     * Seam: the shop user the request names.
     */
    protected function loadUser(string $userId): ?User
    {
        /** @var User $user */
        $user = oxNew(User::class);

        return $user->load($userId) ? $user : null;
    }

    /**
     * Seam: the one oxNew() for the basket core will finalize.
     */
    protected function newBasket(): Basket
    {
        /** @var Basket $basket */
        $basket = oxNew(Basket::class);

        return $basket;
    }

    /**
     * Seam: the delivery set this checkout uses. A storefront choice wins;
     * otherwise the first set the user may use with this basket, which is
     * what core's DeliverySetList::getDeliverySetData() picks for the Twig
     * payment step.
     */
    protected function resolveDeliverySet(User $user, Basket $basket, ?string $preferred): ?string
    {
        if ($preferred !== null) {
            return $preferred;
        }

        /** @var DeliverySetList $deliverySets */
        $deliverySets = Registry::get(DeliverySetList::class);
        $data = $deliverySets->getDeliverySetData(null, $user, $basket);

        $active = is_array($data) ? ($data[1] ?? null) : null;

        return is_string($active) && $active !== '' ? $active : null;
    }
}
