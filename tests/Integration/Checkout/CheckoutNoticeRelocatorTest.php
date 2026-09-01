<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use OxidEsales\Eshop\Core\DisplayError;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Checkout\Contract\CheckoutNoticeRelocatorInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The relocator against the shop's real session and its real DisplayError.
 *
 * The unit tests pin the rule; this proves the two things only the shop can
 * answer: that what UtilsView::addErrorToDisplay() leaves behind is readable
 * here, and that emptying the stash is what stops message/errors.html.twig
 * painting it red.
 */
#[Group('integration')]
class CheckoutNoticeRelocatorTest extends IntegrationTestCase
{
    private const STASH = 'Errors';

    public function setUp(): void
    {
        parent::setUp();

        Registry::getSession()->deleteVariable(self::STASH);
    }

    public function tearDown(): void
    {
        Registry::getSession()->deleteVariable(self::STASH);

        parent::tearDown();
    }

    /**
     * The exact call a PSP makes when the payment is still settling on return.
     */
    public function testTakesWhatAddErrorToDisplayLeftBehind(): void
    {
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_PENDING');

        $notices = $this->relocator()->takeDisplayNotices();

        $this->assertCount(1, $notices, 'the queued message must be readable');
        $this->assertNotSame('', trim($notices[0]));
    }

    /**
     * The stash is what the shop paints red. Emptying it is the whole point.
     */
    public function testEmptiesTheStashSoNothingIsLeftToPaintRed(): void
    {
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_PENDING');
        $this->assertNotEmpty($this->stashedDefault(), 'precondition: the message is queued');

        $this->relocator()->takeDisplayNotices();

        $this->assertSame([], $this->stashedDefault());
    }

    public function testTakesNothingWhenNothingWasQueued(): void
    {
        $this->assertSame([], $this->relocator()->takeDisplayNotices());
    }

    /**
     * A real DisplayError renders its message translated; the relocator must
     * hand the template finished text, not an ident.
     */
    public function testHandsOverTheRenderedMessage(): void
    {
        $error = oxNew(DisplayError::class);
        $error->setMessage('a payment notice for the customer');
        Registry::getUtilsView()->addErrorToDisplay($error);

        $this->assertSame(
            ['a payment notice for the customer'],
            $this->relocator()->takeDisplayNotices()
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function stashedDefault(): array
    {
        $stash = Registry::getSession()->getVariable(self::STASH);

        return is_array($stash) && isset($stash['default']) && is_array($stash['default'])
            ? $stash['default']
            : [];
    }

    private function relocator(): CheckoutNoticeRelocatorInterface
    {
        /** @var CheckoutNoticeRelocatorInterface $relocator */
        $relocator = ContainerFactory::getInstance()->getContainer()
            ->get(CheckoutNoticeRelocatorInterface::class);

        return $relocator;
    }
}
