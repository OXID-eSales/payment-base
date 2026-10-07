<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use InvalidArgumentException;
use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;

/**
 * @since 3.0.0
 */
class GuestUserResolver implements GuestUserResolverInterface
{
    public function resolve(array $buyer, ?array $address): string
    {
        $email = strtolower(trim((string) ($buyer['email'] ?? '')));
        if ($email === '') {
            throw new InvalidArgumentException('A buyer e-mail is required');
        }

        $existing = $this->findUserIdByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createUser($this->fieldsFor($email, $buyer, $address ?? []));
    }

    /**
     * @param array<string, mixed> $buyer
     * @param array<string, mixed> $address
     * @return array<string, string>
     */
    private function fieldsFor(string $email, array $buyer, array $address): array
    {
        [$firstName, $lastName] = $this->names($buyer, $address);

        $countryId = '';
        $iso2 = strtoupper(trim((string) ($address['country'] ?? '')));
        if ($iso2 !== '') {
            $countryId = (string) $this->countryIdFor($iso2);
            if ($countryId === '') {
                throw new InvalidArgumentException(sprintf('Country "%s" is unknown to this shop', $iso2));
            }
        }

        return [
            'oxusername' => $email,
            'oxfname' => $firstName,
            'oxlname' => $lastName,
            'oxfon' => trim((string) ($buyer['phone_number'] ?? '')),
            'oxstreet' => trim((string) ($address['line_one'] ?? '')),
            'oxstreetnr' => '',
            'oxaddinfo' => trim((string) ($address['line_two'] ?? '')),
            'oxcity' => trim((string) ($address['city'] ?? '')),
            'oxzip' => trim((string) ($address['postal_code'] ?? '')),
            'oxcountryid' => $countryId,
            'oxstateid' => trim((string) ($address['state'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $buyer
     * @param array<string, mixed> $address
     * @return array{0: string, 1: string}
     */
    private function names(array $buyer, array $address): array
    {
        $first = trim((string) ($buyer['first_name'] ?? ''));
        $last = trim((string) ($buyer['last_name'] ?? ''));
        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }

        $parts = preg_split('/\s+/', trim((string) ($address['name'] ?? ''))) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        if ($parts === []) {
            return ['', ''];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }

    /**
     * Seam: the user id for an e-mail in this shop, or null.
     */
    protected function findUserIdByEmail(string $email): ?string
    {
        $id = DatabaseProvider::getDb()->getOne(
            'SELECT OXID FROM oxuser WHERE OXUSERNAME = ? AND OXSHOPID = ?',
            [$email, Registry::getConfig()->getShopId()]
        );

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Seam: the shop's country id for an ISO 3166-1 alpha-2 code.
     */
    protected function countryIdFor(string $iso2): ?string
    {
        /** @var Country $country */
        $country = oxNew(Country::class);
        $id = $country->getIdByCode($iso2);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Seam: create the guest account.
     *
     * @param array<string, string> $fields oxuser columns without the table prefix
     */
    protected function createUser(array $fields): string
    {
        /** @var User $user */
        $user = oxNew(User::class);
        $user->oxuser__oxactive = new Field(1, Field::T_RAW);
        $user->oxuser__oxrights = new Field('user', Field::T_RAW);
        $user->oxuser__oxshopid = new Field(Registry::getConfig()->getShopId(), Field::T_RAW);
        $user->oxuser__oxpassword = new Field('', Field::T_RAW);
        foreach ($fields as $column => $value) {
            $property = 'oxuser__' . $column;
            $user->$property = new Field($value, Field::T_RAW);
        }
        $user->save();

        return (string) $user->getId();
    }
}
