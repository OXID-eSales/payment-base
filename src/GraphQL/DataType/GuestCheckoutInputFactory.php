<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\DataType;

use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\GraphQL\Exception\GuestDetailsRefused;
use TheCodingMachine\GraphQLite\Annotations\Factory;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * The `guest` argument of every `<provider>CheckoutStart`: who an anonymous
 * shopper is, as GraphQL input types defined ONCE here and reused by the
 * provider modules (type names are schema-wide, so a second definition in a
 * provider module would clash).
 *
 *   GuestCheckoutInput { email, invoiceAddress: GuestAddressInput!, deliveryAddress: GuestAddressInput }
 *   GuestAddressInput  { firstName, lastName, street, streetNumber, zipCode, city, countryId,
 *                        salutation, company, additionalInfo, stateId, phone }
 *
 * The factories build payment-base's validated value objects directly. Those
 * validate on construction (`guest_details_required`, naming the field, never
 * the value) while GraphQLite resolves the arguments, so a refusal leaves as
 * GuestDetailsRefused, not as a bare HeadlessCheckoutException.
 *
 * Mapped by `NamespaceMapper` with the rest of this namespace, i.e. only
 * where graphql-base is installed; nothing the container reflects touches it.
 *
 * @since 3.6.0
 */
final class GuestCheckoutInputFactory
{
    /**
     * @param ID $countryId the shop's country id (`countries { id }`)
     */
    #[Factory(name: 'GuestAddressInput')]
    public static function guestAddress(
        string $firstName,
        string $lastName,
        string $street,
        string $streetNumber,
        string $zipCode,
        string $city,
        ID $countryId,
        string $salutation = '',
        string $company = '',
        string $additionalInfo = '',
        ?ID $stateId = null,
        string $phone = '',
    ): GuestAddress {
        try {
            return new GuestAddress(
                $firstName,
                $lastName,
                $street,
                $streetNumber,
                $zipCode,
                $city,
                (string) $countryId,
                $salutation,
                $company,
                $additionalInfo,
                $stateId === null ? '' : (string) $stateId,
                $phone
            );
        } catch (HeadlessCheckoutException $refusal) {
            throw new GuestDetailsRefused($refusal);
        }
    }

    #[Factory(name: 'GuestCheckoutInput')]
    public static function guestDetails(
        string $email,
        GuestAddress $invoiceAddress,
        ?GuestAddress $deliveryAddress = null,
    ): GuestDetails {
        try {
            return new GuestDetails($email, $invoiceAddress, $deliveryAddress);
        } catch (HeadlessCheckoutException $refusal) {
            throw new GuestDetailsRefused($refusal);
        }
    }
}
