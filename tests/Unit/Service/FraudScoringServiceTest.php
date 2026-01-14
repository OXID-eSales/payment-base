<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\FraudScoringService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\FraudScoringService
 */
class FraudScoringServiceTest extends TestCase
{
    private FraudScoringService $service;

    protected function setUp(): void
    {
        $this->service = new FraudScoringService();
    }

    public function testCalculatesLowRiskScoreForValidOrder(): void
    {
        // Arrange: Order with matching addresses, valid email, normal amount
        $data = [
            'amount' => 50.00,
            'currency' => 'EUR',
            'billingAddress' => [
                'street' => 'Test Street 1',
                'city' => 'Berlin',
                'zip' => '10115',
                'country' => 'DE',
            ],
            'shippingAddress' => [
                'street' => 'Test Street 1',
                'city' => 'Berlin',
                'zip' => '10115',
                'country' => 'DE',
            ],
            'email' => 'valid.customer@example.com',
            'ipAddress' => '192.168.1.1',
        ];

        // Act
        $score = $this->service->calculateRiskScore($data);

        // Assert: Low risk (0-30)
        $this->assertLessThan(30, $score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    public function testCalculatesMediumRiskScoreForMismatchedAddresses(): void
    {
        // Arrange: Billing and shipping addresses different
        $data = [
            'amount' => 50.00,
            'currency' => 'EUR',
            'billingAddress' => [
                'street' => 'Billing Street 1',
                'city' => 'Berlin',
                'zip' => '10115',
                'country' => 'DE',
            ],
            'shippingAddress' => [
                'street' => 'Shipping Street 99',
                'city' => 'Munich',
                'zip' => '80331',
                'country' => 'DE',
            ],
            'email' => 'customer@example.com',
            'ipAddress' => '192.168.1.1',
        ];

        // Act
        $score = $this->service->calculateRiskScore($data);

        // Assert: Medium risk (30-70)
        $this->assertGreaterThanOrEqual(30, $score);
        $this->assertLessThan(70, $score);
    }

    public function testCalculatesHighRiskScoreForSuspiciousEmail(): void
    {
        // Arrange: Disposable email domain
        $data = [
            'amount' => 100.00,
            'currency' => 'EUR',
            'billingAddress' => ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE'],
            'shippingAddress' => ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE'],
            'email' => 'test@tempmail.com',
            'ipAddress' => '192.168.1.1',
        ];

        // Act
        $score = $this->service->calculateRiskScore($data);

        // Assert: High risk (60+) - Base(10) + Disposable email(50) = 60
        $this->assertGreaterThanOrEqual(60, $score);
    }

    public function testCalculatesHighRiskScoreForLargeAmount(): void
    {
        // Arrange: Large order value (€1000+)
        $data = [
            'amount' => 1500.00,
            'currency' => 'EUR',
            'billingAddress' => ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE'],
            'shippingAddress' => ['street' => 'Test', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE'],
            'email' => 'customer@example.com',
            'ipAddress' => '192.168.1.1',
        ];

        // Act
        $score = $this->service->calculateRiskScore($data);

        // Assert: Increased risk for large amounts
        $this->assertGreaterThan(20, $score);
    }

    public function testCalculatesHighRiskScoreForMultipleFactors(): void
    {
        // Arrange: Multiple risk factors (mismatched addresses + disposable email + high amount)
        $data = [
            'amount' => 999.00,
            'currency' => 'EUR',
            'billingAddress' => ['street' => 'Billing', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE'],
            'shippingAddress' => ['street' => 'Shipping', 'city' => 'Munich', 'zip' => '80331', 'country' => 'DE'],
            'email' => 'test@guerrillamail.com',
            'ipAddress' => '192.168.1.1',
        ];

        // Act
        $score = $this->service->calculateRiskScore($data);

        // Assert: Very high risk (80+) due to multiple factors
        $this->assertGreaterThanOrEqual(80, $score);
    }

    public function testIsDisposableEmailDomain(): void
    {
        $this->assertTrue($this->service->isDisposableEmail('test@tempmail.com'));
        $this->assertTrue($this->service->isDisposableEmail('user@guerrillamail.com'));
        $this->assertTrue($this->service->isDisposableEmail('test@10minutemail.com'));
        $this->assertFalse($this->service->isDisposableEmail('customer@example.com'));
        $this->assertFalse($this->service->isDisposableEmail('user@gmail.com'));
    }

    public function testAddressesMatch(): void
    {
        $address1 = [
            'street' => 'Test Street 1',
            'city' => 'Berlin',
            'zip' => '10115',
            'country' => 'DE',
        ];

        $address2 = [
            'street' => 'Test Street 1',
            'city' => 'Berlin',
            'zip' => '10115',
            'country' => 'DE',
        ];

        $address3 = [
            'street' => 'Different Street',
            'city' => 'Munich',
            'zip' => '80331',
            'country' => 'DE',
        ];

        $this->assertTrue($this->service->addressesMatch($address1, $address2));
        $this->assertFalse($this->service->addressesMatch($address1, $address3));
    }
}
