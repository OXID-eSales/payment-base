<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * An address of a shopper without an account. Field names follow the
 * storefront's `InvoiceAddressInput` / `DeliveryAddressInput`; `countryId` is
 * the shop's country id (`countries { id }`). Required fields are validated
 * on construction, optional ones are empty strings, never null.
 *
 * @since 1.5.0
 */
final readonly class GuestAddress
{
    private const SHORT_COLUMN_LENGTH = 16;
    private const LONG_COLUMN_LENGTH = 255;

    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $street,
        public string $streetNumber,
        public string $zipCode,
        public string $city,
        public string $countryId,
        public string $salutation = '',
        public string $company = '',
        public string $additionalInfo = '',
        public string $stateId = '',
        public string $phone = '',
    ) {
        $this->assertFilled('firstName', $firstName, self::LONG_COLUMN_LENGTH);
        $this->assertFilled('lastName', $lastName, self::LONG_COLUMN_LENGTH);
        $this->assertFilled('street', $street, self::LONG_COLUMN_LENGTH);
        $this->assertFilled('streetNumber', $streetNumber, self::SHORT_COLUMN_LENGTH);
        $this->assertFilled('zipCode', $zipCode, self::SHORT_COLUMN_LENGTH);
        $this->assertFilled('city', $city, self::LONG_COLUMN_LENGTH);
        $this->assertFilled('countryId', $countryId, self::LONG_COLUMN_LENGTH);
    }

    /**
     * Longer than the shop's column: refused here, not silently cut by MySQL.
     */
    private function assertFilled(string $field, string $value, int $maxLength): void
    {
        if (trim($value) === '' || strlen($value) > $maxLength) {
            throw HeadlessCheckoutException::guestDetailsRequired($field);
        }
    }
}
