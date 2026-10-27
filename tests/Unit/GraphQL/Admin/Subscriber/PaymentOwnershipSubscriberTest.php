<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership;
use OxidEsales\PaymentBase\GraphQL\Admin\Subscriber\PaymentOwnershipSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentOwnershipSubscriberTest extends TestCase
{
    public function testSubscribesToTheOwnershipEvent(): void
    {
        self::assertSame(
            [AdminPaymentOwnership::class => 'onOwnership'],
            PaymentOwnershipSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerRows(): iterable
    {
        yield 'stripe' => ['oe_payments_stripe_wallet', 'stripe'];
        yield 'paypal (one payment method, never a row per funding source)' => ['oe_payments_paypal', 'paypal'];
        yield 'mollie' => ['oe_payments_mollie', 'mollie'];
    }

    #[DataProvider('providerRows')]
    public function testOnOwnership_ProviderRow_IsClaimedWithItsPresentationFields(string $paymentId, string $provider): void
    {
        $event = new AdminPaymentOwnership($paymentId);

        (new PaymentOwnershipSubscriber())->onOwnership($event);

        $claim = $event->claimed();
        self::assertNotNull($claim);
        self::assertSame($provider, $claim->provider);
        self::assertSame(['title', 'description'], $claim->readOnlyFields);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shopRows(): iterable
    {
        yield 'invoice' => ['oxidinvoice'];
        yield 'cash on delivery' => ['oxidcashondel'];
        yield 'a funding-source id PayPal never creates' => ['oe_payments_paypal_paylater'];
        yield 'empty' => [''];
    }

    #[DataProvider('shopRows')]
    public function testOnOwnership_AnyOtherRow_StaysTheShops(string $paymentId): void
    {
        $event = new AdminPaymentOwnership($paymentId);

        (new PaymentOwnershipSubscriber())->onOwnership($event);

        self::assertNull($event->claimed());
    }
}
