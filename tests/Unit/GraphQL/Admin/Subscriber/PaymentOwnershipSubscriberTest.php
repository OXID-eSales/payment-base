<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership;
use OxidEsales\PaymentBase\GraphQL\Admin\Contract\PaymentOwnershipProviderInterface;
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

    public function testOnOwnership_RowOfATaggedProvider_IsClaimedWithItsPresentationFields(): void
    {
        $event = new AdminPaymentOwnership('oe_payments_acme');

        $this->subscriber($this->provider('acme', 'oe_payments_acme'))->onOwnership($event);

        $claim = $event->claimed();
        self::assertNotNull($claim);
        self::assertSame('acme', $claim->provider);
        self::assertSame(['title', 'description'], $claim->readOnlyFields);
    }

    public function testOnOwnership_ANewProviderNeedsNoChangeInPaymentBase(): void
    {
        $event = new AdminPaymentOwnership('oe_payments_fourth');

        $this->subscriber(
            $this->provider('acme', 'oe_payments_acme'),
            $this->provider('fourth', 'oe_payments_fourth'),
        )->onOwnership($event);

        self::assertSame('fourth', $event->claimed()?->provider);
    }

    public function testOnOwnership_ProviderOwningSeveralRows_ClaimsEachOfThem(): void
    {
        $event = new AdminPaymentOwnership('oe_payments_acme_b');

        $this->subscriber($this->provider('acme', 'oe_payments_acme_a', 'oe_payments_acme_b'))->onOwnership($event);

        self::assertSame('acme', $event->claimed()?->provider);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shopRows(): iterable
    {
        yield 'invoice' => ['oxidinvoice'];
        yield 'cash on delivery' => ['oxidcashondel'];
        yield 'a funding-source id the provider never creates' => ['oe_payments_acme_paylater'];
        yield 'empty' => [''];
    }

    #[DataProvider('shopRows')]
    public function testOnOwnership_AnyOtherRow_StaysTheShops(string $paymentId): void
    {
        $event = new AdminPaymentOwnership($paymentId);

        $this->subscriber($this->provider('acme', 'oe_payments_acme'))->onOwnership($event);

        self::assertNull($event->claimed());
    }

    public function testOnOwnership_NoProviderTagged_ClaimsNothing(): void
    {
        $event = new AdminPaymentOwnership('oe_payments_acme');

        $this->subscriber()->onOwnership($event);

        self::assertNull($event->claimed());
    }

    private function subscriber(PaymentOwnershipProviderInterface ...$providers): PaymentOwnershipSubscriber
    {
        return new PaymentOwnershipSubscriber($providers);
    }

    private function provider(string $name, string ...$paymentIds): PaymentOwnershipProviderInterface
    {
        return new class ($name, array_values($paymentIds)) implements PaymentOwnershipProviderInterface {
            /**
             * @param list<string> $paymentIds
             */
            public function __construct(private readonly string $name, private readonly array $paymentIds)
            {
            }

            public function providerName(): string
            {
                return $this->name;
            }

            public function paymentIds(): array
            {
                return $this->paymentIds;
            }
        };
    }
}
