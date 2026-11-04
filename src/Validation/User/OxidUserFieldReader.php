<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\User;

use OxidEsales\Eshop\Application\Model\Address;
use OxidEsales\Eshop\Application\Model\User;

/**
 * Reads the logical address fields from a live OXID User (billing) and its selected delivery
 * Address. The logical-name to column map lives here once.
 *
 * Bound to one User, so it is created per request, never registered as a service.
 */
final class OxidUserFieldReader implements UserFieldReaderInterface
{
    /**
     * Logical name => OXID column suffix (same for oxuser__ and oxaddress__).
     *
     * @var array<string, string>
     */
    private const MAP = [
        'firstName' => 'oxfname',
        'lastName' => 'oxlname',
        'additionalInfo' => 'oxaddinfo',
        'street' => 'oxstreet',
        'houseNumber' => 'oxstreetnr',
        'postalCode' => 'oxzip',
        'city' => 'oxcity',
        'company' => 'oxcompany',
        'vatId' => 'oxustid',
        'phone' => 'oxfon',
        'cellPhone' => 'oxprivfon',
        'personalPhone' => 'oxmobfon',
        'fax' => 'oxfax',
        'email' => 'oxusername',
    ];

    private ?Address $deliveryAddress;

    public function __construct(private readonly User $user)
    {
        /** @phpstan-ignore-next-line OXID core: getSelectedAddress() on virtual User object */
        $address = $user->getSelectedAddress();
        $this->deliveryAddress = $address instanceof Address ? $address : null;
    }

    public function readBillingField(string $logicalName): string
    {
        $column = self::MAP[$logicalName] ?? null;
        if ($column === null) {
            return '';
        }

        /** @phpstan-ignore-next-line OXID core: getFieldData() on virtual User object */
        $value = $this->user->getFieldData($column);

        return is_string($value) ? $value : '';
    }

    public function hasDeliveryAddress(): bool
    {
        return $this->deliveryAddress !== null;
    }

    public function readDeliveryField(string $logicalName): string
    {
        $column = self::MAP[$logicalName] ?? null;
        if ($column === null || $this->deliveryAddress === null) {
            return '';
        }

        /** @phpstan-ignore-next-line OXID core: getFieldData() on virtual Address object */
        $value = $this->deliveryAddress->getFieldData($column);

        return is_string($value) ? $value : '';
    }

    /** OXID column name of a logical field, or null when unknown. */
    public static function oxidColumn(string $logicalName): ?string
    {
        return self::MAP[$logicalName] ?? null;
    }
}
