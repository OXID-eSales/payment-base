<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Controller;

use OxidEsales\PaymentBase\Checkout\Contract\CheckoutNoticeRelocatorInterface;
use OxidEsales\PaymentBase\Eshop\Application\Controller\ThankYouController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Counts how often the stash was asked for — the stash empties on the first
 * read, so a second read would answer nothing.
 */
class SpyNoticeRelocator implements CheckoutNoticeRelocatorInterface
{
    public int $calls = 0;

    /** @param array<int, string> $notices */
    public function __construct(private readonly array $notices = [])
    {
    }

    public function takeDisplayNotices(): array
    {
        $this->calls++;

        return $this->notices;
    }
}

class ThrowingNoticeRelocator implements CheckoutNoticeRelocatorInterface
{
    public function takeDisplayNotices(): array
    {
        throw new RuntimeException('no session');
    }
}

final class TestableThankYouController extends ThankYouController
{
    public function __construct(private readonly CheckoutNoticeRelocatorInterface $relocator)
    {
    }

    protected function getNoticeRelocator(): CheckoutNoticeRelocatorInterface
    {
        return $this->relocator;
    }
}

/**
 * A payment left settling on return reaches the thank-you page through the
 * shop's display-error stash, which the shop paints red above the page. The
 * controller takes it out so the template can show it as a notice.
 */
final class ThankYouControllerTest extends TestCase
{
    public function testRenderTakesTheQueuedNoticesAndOffersThemToTheTemplate(): void
    {
        $controller = new TestableThankYouController(
            new SpyNoticeRelocator(['Your payment is being processed.'])
        );

        $controller->render();

        $this->assertSame(['Your payment is being processed.'], $controller->getPaymentNotices());
    }

    /**
     * Taking them is what empties the stash, so asking twice would lose them.
     */
    public function testTheStashIsOnlyTakenOnce(): void
    {
        $relocator = new SpyNoticeRelocator(['processing']);
        $controller = new TestableThankYouController($relocator);

        $controller->render();
        $controller->render();

        $this->assertSame(1, $relocator->calls);
        $this->assertSame(['processing'], $controller->getPaymentNotices());
    }

    public function testAnEmptyStashLeavesNothingToRender(): void
    {
        $controller = new TestableThankYouController(new SpyNoticeRelocator());

        $controller->render();

        $this->assertSame([], $controller->getPaymentNotices());
    }

    /**
     * Read before render: a template asking early must not get a stale answer.
     */
    public function testNoticesAreEmptyBeforeTheePageRenders(): void
    {
        $controller = new TestableThankYouController(new SpyNoticeRelocator(['processing']));

        $this->assertSame([], $controller->getPaymentNotices());
    }

    /**
     * The confirmation page matters more than the notice on it.
     */
    public function testAFailingRelocatorDoesNotCostTheCustomerTheirConfirmation(): void
    {
        $controller = new TestableThankYouController(new ThrowingNoticeRelocator());

        $controller->render();

        $this->assertSame([], $controller->getPaymentNotices());
    }
}
