<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Contract;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Contract\PaymentCustomer;
use OxidEsales\PaymentComponent\Model\ModelInterface;
use PHPUnit\Framework\TestCase;

class PaymentCustomerTest extends TestCase
{
    public function testConstructorSetsRequiredFields(): void
    {
        $now = new DateTimeImmutable('2026-02-06 12:00:00');
        $customer = new PaymentCustomer('id_123', 'user_abc', $now, $now);

        $this->assertEquals('id_123', $customer->getId());
        $this->assertEquals('user_abc', $customer->getUserId());
        $this->assertEquals($now, $customer->getCreatedAt());
        $this->assertEquals($now, $customer->getUpdatedAt());
    }

    public function testDefaultsAreNull(): void
    {
        $now = new DateTimeImmutable();
        $customer = new PaymentCustomer('id_1', 'user_1', $now, $now);

        $this->assertNull($customer->getPaymentCustomerId());
        $this->assertNull($customer->getDefaultPaymentMethod());
        $this->assertNull($customer->getSavedPaymentMethods());
        $this->assertFalse($customer->getBillingAgreement());
        $this->assertNull($customer->getLastPaymentDate());
    }

    public function testImplementsModelInterface(): void
    {
        $now = new DateTimeImmutable();
        $customer = new PaymentCustomer('id_1', 'user_1', $now, $now);

        $this->assertInstanceOf(ModelInterface::class, $customer);
    }

    public function testSetPaymentCustomerId(): void
    {
        $customer = $this->createCustomer();
        $customer->setPaymentCustomerId('cus_stripe_123');

        $this->assertEquals('cus_stripe_123', $customer->getPaymentCustomerId());
    }

    public function testSetDefaultPaymentMethod(): void
    {
        $customer = $this->createCustomer();
        $customer->setDefaultPaymentMethod('pm_card_visa');

        $this->assertEquals('pm_card_visa', $customer->getDefaultPaymentMethod());
    }

    public function testSetSavedPaymentMethods(): void
    {
        $customer = $this->createCustomer();
        $json = '["pm_1","pm_2"]';
        $customer->setSavedPaymentMethods($json);

        $this->assertEquals($json, $customer->getSavedPaymentMethods());
    }

    public function testSetBillingAgreement(): void
    {
        $customer = $this->createCustomer();
        $customer->setBillingAgreement(true);

        $this->assertTrue($customer->getBillingAgreement());
    }

    public function testSetLastPaymentDate(): void
    {
        $customer = $this->createCustomer();
        $date = new DateTimeImmutable('2026-01-15 10:30:00');
        $customer->setLastPaymentDate($date);

        $this->assertEquals($date, $customer->getLastPaymentDate());
    }

    public function testSetUpdatedAt(): void
    {
        $customer = $this->createCustomer();
        $newDate = new DateTimeImmutable('2026-03-01 09:00:00');
        $customer->setUpdatedAt($newDate);

        $this->assertEquals($newDate, $customer->getUpdatedAt());
    }

    public function testToArray(): void
    {
        $created = new DateTimeImmutable('2026-02-06 12:00:00');
        $updated = new DateTimeImmutable('2026-02-06 13:00:00');
        $lastPayment = new DateTimeImmutable('2026-02-05 10:00:00');

        $customer = new PaymentCustomer('id_test', 'user_test', $created, $updated);
        $customer->setPaymentCustomerId('cus_abc');
        $customer->setDefaultPaymentMethod('pm_xyz');
        $customer->setSavedPaymentMethods('["pm_xyz"]');
        $customer->setBillingAgreement(true);
        $customer->setLastPaymentDate($lastPayment);

        $array = $customer->toArray();

        $this->assertEquals('id_test', $array['id']);
        $this->assertEquals('user_test', $array['userId']);
        $this->assertEquals('cus_abc', $array['paymentCustomerId']);
        $this->assertEquals('pm_xyz', $array['defaultPaymentMethod']);
        $this->assertEquals('["pm_xyz"]', $array['savedPaymentMethods']);
        $this->assertTrue($array['billingAgreement']);
        $this->assertEquals('2026-02-05 10:00:00', $array['lastPaymentDate']);
        $this->assertEquals('2026-02-06 12:00:00', $array['createdAt']);
        $this->assertEquals('2026-02-06 13:00:00', $array['updatedAt']);
    }

    public function testToArrayWithNullOptionalFields(): void
    {
        $now = new DateTimeImmutable('2026-02-06 12:00:00');
        $customer = new PaymentCustomer('id_1', 'user_1', $now, $now);

        $array = $customer->toArray();

        $this->assertNull($array['paymentCustomerId']);
        $this->assertNull($array['defaultPaymentMethod']);
        $this->assertNull($array['savedPaymentMethods']);
        $this->assertFalse($array['billingAgreement']);
        $this->assertNull($array['lastPaymentDate']);
    }

    public function testFromArray(): void
    {
        $data = [
            'id' => 'id_from',
            'userId' => 'user_from',
            'paymentCustomerId' => 'cus_from',
            'defaultPaymentMethod' => 'pm_from',
            'savedPaymentMethods' => '["pm_from"]',
            'billingAgreement' => true,
            'lastPaymentDate' => '2026-01-20 15:00:00',
            'createdAt' => '2026-02-06 10:00:00',
            'updatedAt' => '2026-02-06 11:00:00',
        ];

        $customer = PaymentCustomer::fromArray($data);

        $this->assertEquals('id_from', $customer->getId());
        $this->assertEquals('user_from', $customer->getUserId());
        $this->assertEquals('cus_from', $customer->getPaymentCustomerId());
        $this->assertEquals('pm_from', $customer->getDefaultPaymentMethod());
        $this->assertEquals('["pm_from"]', $customer->getSavedPaymentMethods());
        $this->assertTrue($customer->getBillingAgreement());
        $this->assertEquals('2026-01-20 15:00:00', $customer->getLastPaymentDate()?->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-02-06 10:00:00', $customer->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-02-06 11:00:00', $customer->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = [
            'id' => 'id_min',
            'userId' => 'user_min',
            'createdAt' => '2026-02-06 10:00:00',
            'updatedAt' => '2026-02-06 10:00:00',
        ];

        $customer = PaymentCustomer::fromArray($data);

        $this->assertEquals('id_min', $customer->getId());
        $this->assertEquals('user_min', $customer->getUserId());
        $this->assertNull($customer->getPaymentCustomerId());
        $this->assertNull($customer->getDefaultPaymentMethod());
        $this->assertNull($customer->getSavedPaymentMethods());
        $this->assertFalse($customer->getBillingAgreement());
        $this->assertNull($customer->getLastPaymentDate());
    }

    public function testRoundTripSerialization(): void
    {
        $created = new DateTimeImmutable('2026-02-06 12:00:00');
        $updated = new DateTimeImmutable('2026-02-06 13:00:00');

        $original = new PaymentCustomer('rt_id', 'rt_user', $created, $updated);
        $original->setPaymentCustomerId('cus_roundtrip');
        $original->setBillingAgreement(true);

        $restored = PaymentCustomer::fromArray($original->toArray());

        $this->assertEquals($original->getId(), $restored->getId());
        $this->assertEquals($original->getUserId(), $restored->getUserId());
        $this->assertEquals($original->getPaymentCustomerId(), $restored->getPaymentCustomerId());
        $this->assertEquals($original->getBillingAgreement(), $restored->getBillingAgreement());
        $this->assertEquals(
            $original->getCreatedAt()->format('Y-m-d H:i:s'),
            $restored->getCreatedAt()->format('Y-m-d H:i:s')
        );
    }

    private function createCustomer(): PaymentCustomer
    {
        return new PaymentCustomer(
            'test_id',
            'test_user',
            new DateTimeImmutable('2026-02-06 12:00:00'),
            new DateTimeImmutable('2026-02-06 12:00:00')
        );
    }
}
