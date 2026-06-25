<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Broker;

use InvalidArgumentException;
use OxidEsales\PaymentBase\EventSystem\Broker\ConventionProviderEventResolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * STRP-AUTOCAP-REFUND — unit tests for the convention-based provider event
 * resolver. Locks in the FQCN template:
 *
 *     OxidEsales\Payments\{Canonical}\EventSystem\Event\{Canonical}{BaseName}
 *
 * Adding a new payment provider must require zero changes to payment-base
 * as long as it conforms to this convention.
 */
#[Group('strp-autocap-refund')]
final class ConventionProviderEventResolverTest extends TestCase
{
    public function testResolvesStripeRefundRequestEventByUcfirstConvention(): void
    {
        $resolver = new ConventionProviderEventResolver();

        self::assertSame(
            'OxidEsales\\Payments\\Stripe\\EventSystem\\Event\\StripeRefundRequestEvent',
            $resolver->resolveClassName('stripe', 'RefundRequestEvent'),
        );
    }

    public function testResolvesStripeCaptureRequestEventByUcfirstConvention(): void
    {
        $resolver = new ConventionProviderEventResolver();

        self::assertSame(
            'OxidEsales\\Payments\\Stripe\\EventSystem\\Event\\StripeCaptureRequestEvent',
            $resolver->resolveClassName('stripe', 'CaptureRequestEvent'),
        );
    }

    public function testResolvesPayPalEventByRegisteredCanonicalName(): void
    {
        // 'paypal' does not match the canonical PascalCase 'PayPal' via
        // ucfirst alone — must register the canonical name explicitly.
        $resolver = new ConventionProviderEventResolver();
        $resolver->registerCanonicalName('paypal', 'PayPal');

        self::assertSame(
            'OxidEsales\\Payments\\PayPal\\EventSystem\\Event\\PayPalRefundRequestEvent',
            $resolver->resolveClassName('paypal', 'RefundRequestEvent'),
        );
        self::assertSame(
            'OxidEsales\\Payments\\PayPal\\EventSystem\\Event\\PayPalCaptureRequestEvent',
            $resolver->resolveClassName('paypal', 'CaptureRequestEvent'),
        );
    }

    public function testFallsBackToUcfirstWhenNoCanonicalNameRegistered(): void
    {
        // Hypothetical third-party provider 'klarna' whose canonical
        // matches ucfirst — no registration needed.
        $resolver = new ConventionProviderEventResolver();

        self::assertSame(
            'OxidEsales\\Payments\\Klarna\\EventSystem\\Event\\KlarnaRefundRequestEvent',
            $resolver->resolveClassName('klarna', 'RefundRequestEvent'),
        );
    }

    public function testLowercasesProviderIdBeforeApplyingUcfirst(): void
    {
        // Defence in depth — accept either 'stripe' or 'STRIPE' or 'Stripe'.
        $resolver = new ConventionProviderEventResolver();

        $expected = 'OxidEsales\\Payments\\Stripe\\EventSystem\\Event\\StripeRefundRequestEvent';

        self::assertSame($expected, $resolver->resolveClassName('STRIPE', 'RefundRequestEvent'));
        self::assertSame($expected, $resolver->resolveClassName('Stripe', 'RefundRequestEvent'));
    }

    public function testCanonicalRegistrationIsCaseInsensitiveOnProviderId(): void
    {
        $resolver = new ConventionProviderEventResolver();
        $resolver->registerCanonicalName('PAYPAL', 'PayPal');

        self::assertSame(
            'OxidEsales\\Payments\\PayPal\\EventSystem\\Event\\PayPalRefundRequestEvent',
            $resolver->resolveClassName('paypal', 'RefundRequestEvent'),
        );
    }

    public function testRejectsEmptyProviderId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConventionProviderEventResolver())->resolveClassName('', 'RefundRequestEvent');
    }

    public function testRejectsEmptyBaseName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConventionProviderEventResolver())->resolveClassName('stripe', '');
    }

    public function testRejectsRegisterCanonicalNameWithEmptyValues(): void
    {
        $resolver = new ConventionProviderEventResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->registerCanonicalName('', 'PayPal');
    }

    public function testKnownPaymentBaseEventBaseNamesResolveToRealClasses(): void
    {
        // Smoke: every payment-base generic request event must have a
        // convention class for in-tree providers (Stripe + PayPal).
        //
        // This test asserts cross-package class loadability and can only
        // run when the consumer packages are present in the autoloader
        // (i.e. inside the full OXID shop, not payment-base's standalone
        // composer install). Skip cleanly in standalone mode — Sprint 03
        // adds the integration-mode version of this assertion under
        // opalreturns/tests/Integration where Stripe IS loaded.
        if (!class_exists(\OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent::class)) {
            self::markTestSkipped(
                'Cross-package smoke test — Stripe is not autoloaded in this run. '
                . 'Runs in the shop-wide phpunit configuration via Sprint 03 integration tests.'
            );
        }

        $resolver = new ConventionProviderEventResolver();
        $resolver->registerCanonicalName('paypal', 'PayPal');

        $bases = ['RefundRequestEvent', 'CaptureRequestEvent', 'CancelAuthorizationRequestEvent'];

        foreach ($bases as $base) {
            self::assertTrue(
                class_exists($resolver->resolveClassName('stripe', $base)),
                "Stripe convention class for {$base} must be loadable"
            );
            self::assertTrue(
                class_exists($resolver->resolveClassName('paypal', $base)),
                "PayPal convention class for {$base} must be loadable"
            );
        }
    }
}
