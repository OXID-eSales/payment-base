<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Address;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUser;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;

/**
 * The shop user behind an anonymous checkout. An e-mail nobody has used
 * becomes a guest account (no password: it cannot log in); a guest e-mail
 * is the same account again with this checkout's addresses; an e-mail of a
 * registered account (it has a password) is refused - a stranger must not
 * change an account's addresses, and the shopper should log in.
 *
 * The shop's models are touched in the protected seams only.
 *
 * @since 1.5.0
 */
class OxidGuestUserService implements GuestUserServiceInterface
{
    public function userFor(GuestDetails $details): GuestUser
    {
        $email = strtolower(trim($details->email));
        $account = $this->findAccount($email);
        if ($account !== null && $account['passwordHash'] !== '') {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::ACCOUNT_EXISTS,
                'The e-mail belongs to a registered account; log in to check out'
            );
        }
        $this->assertCountryKnown($details->invoiceAddress);
        if ($details->deliveryAddress !== null) {
            $this->assertCountryKnown($details->deliveryAddress);
        }

        $userId = $this->saveUser($account['id'] ?? null, $this->userColumns($email, $details->invoiceAddress));
        $deliveryAddressId = $this->replaceDeliveryAddress(
            $userId,
            $details->deliveryAddress === null ? null : $this->columnsOf($details->deliveryAddress)
        );

        return new GuestUser($userId, $deliveryAddressId);
    }

    private function assertCountryKnown(GuestAddress $address): void
    {
        if (!$this->countryExists($address->countryId)) {
            throw HeadlessCheckoutException::guestDetailsRequired('countryId');
        }
    }

    /**
     * @return array<string, string>
     */
    private function userColumns(string $email, GuestAddress $invoiceAddress): array
    {
        // An empty password is what makes the account a guest: nobody can log in to it.
        return [
            'oxusername' => $email,
            'oxactive' => '1',
            'oxrights' => 'user',
            'oxpassword' => '',
        ] + $this->columnsOf($invoiceAddress);
    }

    /**
     * The `oxuser` / `oxaddress` columns both tables share.
     *
     * @return array<string, string>
     */
    private function columnsOf(GuestAddress $address): array
    {
        return [
            'oxsal' => $address->salutation,
            'oxfname' => trim($address->firstName),
            'oxlname' => trim($address->lastName),
            'oxcompany' => trim($address->company),
            'oxstreet' => trim($address->street),
            'oxstreetnr' => trim($address->streetNumber),
            'oxaddinfo' => trim($address->additionalInfo),
            'oxzip' => trim($address->zipCode),
            'oxcity' => trim($address->city),
            'oxcountryid' => $address->countryId,
            'oxstateid' => $address->stateId,
            'oxfon' => trim($address->phone),
        ];
    }

    /**
     * Seam: the account of an e-mail in this shop; an empty hash is a guest.
     *
     * @return array{id: string, passwordHash: string}|null
     */
    protected function findAccount(string $email): ?array
    {
        $row = DatabaseProvider::getDb()->getRow(
            'SELECT OXID, OXPASSWORD FROM oxuser WHERE OXUSERNAME = ? AND OXSHOPID = ?',
            [$email, Registry::getConfig()->getShopId()]
        );
        if (!is_array($row) || !isset($row[0])) {
            return null;
        }

        return ['id' => (string) $row[0], 'passwordHash' => (string) ($row[1] ?? '')];
    }

    /**
     * Seam: is this one of the shop's active countries.
     */
    protected function countryExists(string $countryId): bool
    {
        $found = DatabaseProvider::getDb()->getOne(
            'SELECT OXID FROM oxcountry WHERE OXID = ? AND OXACTIVE = 1',
            [$countryId]
        );

        return is_string($found) && $found !== '';
    }

    /**
     * Seam: create (null id) or update the guest account.
     *
     * @param array<string, string> $fields oxuser columns without the table prefix
     */
    protected function saveUser(?string $id, array $fields): string
    {
        /** @var User $user */
        $user = oxNew(User::class);
        if ($id !== null) {
            $user->load($id);
        }
        $fields['oxshopid'] = (string) Registry::getConfig()->getShopId();
        foreach ($fields as $column => $value) {
            $property = 'oxuser__' . $column;
            $user->$property = new Field($value, Field::T_RAW);
        }
        $user->save();

        return (string) $user->getId();
    }

    /**
     * Seam: a guest keeps no address book - what an earlier checkout left is
     * dropped and the delivery address of this one (if any) is stored.
     *
     * @param array<string, string>|null $fields oxaddress columns without the table prefix
     */
    protected function replaceDeliveryAddress(string $userId, ?array $fields): ?string
    {
        DatabaseProvider::getDb()->execute('DELETE FROM oxaddress WHERE OXUSERID = ?', [$userId]);
        if ($fields === null) {
            return null;
        }

        /** @var Address $address */
        $address = oxNew(Address::class);
        $fields += ['oxuserid' => $userId, 'oxaddressuserid' => $userId];
        foreach ($fields as $column => $value) {
            $property = 'oxaddress__' . $column;
            $address->$property = new Field($value, Field::T_RAW);
        }
        $address->save();

        return (string) $address->getId();
    }
}
