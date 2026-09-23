<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Adapter\OrderShippingAddressCopier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 10 (2026-09-23) — core's Order::setUser() only writes OXDEL* when a
 * separate oxaddress row was selected, so a shopper who ships to their
 * billing address leaves every OXDEL* column empty. These tests pin the pure
 * field-mapping that fixes that, in isolation from OXID's shop bootstrap.
 */
#[CoversClass(OrderShippingAddressCopier::class)]
final class OrderShippingAddressCopierTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const BILLING_VALUES = [
        'oxbillcompany' => 'Acme Inc',
        'oxbillfname' => 'Jane',
        'oxbilllname' => 'Doe',
        'oxbillstreet' => 'Main Street',
        'oxbillstreetnr' => '12',
        'oxbilladdinfo' => 'Floor 3',
        'oxbillcity' => 'Freiburg',
        'oxbillcountryid' => 'a7c40f631fc920687.20179984',
        'oxbillstateid' => 'US-CA',
        'oxbillzip' => '79098',
        'oxbillfon' => '0761123456',
        'oxbillfax' => '0761654321',
        'oxbillsal' => 'MRS',
    ];

    /**
     * @var array<int, string>
     */
    private const FIELD_SUFFIXES = [
        'company', 'fname', 'lname', 'street', 'streetnr', 'addinfo', 'city',
        'countryid', 'stateid', 'zip', 'fon', 'fax', 'sal',
    ];

    public function testCopyBillingWhenShippingEmpty_WhenShippingEmpty_CopiesAllThirteenFields(): void
    {
        $order = $this->orderWithShipping(lastName: '', firstName: '');

        (new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order);

        foreach (self::FIELD_SUFFIXES as $suffix) {
            $expected = self::BILLING_VALUES["oxbill$suffix"];
            self::assertSame(
                $expected,
                $order->{"oxorder__oxdel$suffix"}->value,
                "oxdel$suffix should equal oxbill$suffix"
            );
        }
    }

    public function testCopyBillingWhenShippingEmpty_WhenShippingEmpty_ReturnsTrue(): void
    {
        $order = $this->orderWithShipping(lastName: '', firstName: '');

        self::assertTrue((new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order));
    }

    public function testCopyBillingWhenShippingEmpty_WhenShippingLastNamePresent_LeavesOrderUntouchedAndReturnsFalse(): void
    {
        $order = $this->orderWithShipping(lastName: 'Existing', firstName: '');

        $result = (new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order);

        self::assertFalse($result);
        foreach (self::FIELD_SUFFIXES as $suffix) {
            self::assertFalse(
                property_exists($order, "oxorder__oxdel$suffix"),
                "oxdel$suffix must not be written when shipping already has a last name"
            );
        }
    }

    public function testCopyBillingWhenShippingEmpty_WhenOnlyShippingFirstNamePresent_LeavesOrderUntouched(): void
    {
        $order = $this->orderWithShipping(lastName: '', firstName: 'Existing');

        (new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order);

        foreach (self::FIELD_SUFFIXES as $suffix) {
            self::assertFalse(
                property_exists($order, "oxorder__oxdel$suffix"),
                "oxdel$suffix must not be written when shipping already has a first name"
            );
        }
    }

    public function testCopyBillingWhenShippingEmpty_DoesNotTouchFieldsWithoutShippingTwin(): void
    {
        $order = $this->orderWithShipping(lastName: '', firstName: '');

        (new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order);

        self::assertFalse(property_exists($order, 'oxorder__oxdelemail'));
        self::assertFalse(property_exists($order, 'oxorder__oxdelustid'));

        $writtenDelFields = array_filter(
            array_keys(get_object_vars($order)),
            static fn (string $name): bool => str_starts_with($name, 'oxorder__oxdel')
        );
        $expectedDelFields = array_map(
            static fn (string $suffix): string => "oxorder__oxdel$suffix",
            self::FIELD_SUFFIXES
        );

        sort($writtenDelFields);
        sort($expectedDelFields);
        self::assertSame($expectedDelFields, array_values($writtenDelFields));
    }

    public function testCopyBillingWhenShippingEmpty_WritesRawFields(): void
    {
        $order = $this->orderWithShipping(lastName: '', firstName: '');

        (new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order);

        foreach (self::FIELD_SUFFIXES as $suffix) {
            $field = $order->{"oxorder__oxdel$suffix"};
            self::assertSame(self::BILLING_VALUES["oxbill$suffix"], $field->rawValue);
            self::assertSame(self::BILLING_VALUES["oxbill$suffix"], $field->value);
        }
    }

    public function testCopyBillingWhenShippingEmpty_NeverSaves(): void
    {
        $order = $this->orderWithShipping(lastName: '', firstName: '');
        $order->expects(self::never())->method('save');

        (new OrderShippingAddressCopier())->copyBillingWhenShippingEmpty($order);
    }

    /**
     * @return Order&MockObject
     */
    private function orderWithShipping(string $lastName, string $firstName): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'getFieldData'])
            ->getMock();

        $fieldMap = array_merge(self::BILLING_VALUES, [
            'oxdellname' => $lastName,
            'oxdelfname' => $firstName,
        ]);

        $order->method('getFieldData')->willReturnCallback(
            static fn (string $field): string => $fieldMap[$field] ?? ''
        );

        return $order;
    }
}
