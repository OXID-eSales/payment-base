<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Stands in for the extended thank-you controller. */
class ThankYouProbeView
{
    /** @param array<int, string> $notices */
    public function __construct(private readonly array $notices)
    {
    }

    /** @return array<int, string> */
    public function getPaymentNotices(): array
    {
        return $this->notices;
    }

    public function getOrder(): object
    {
        return new ThankYouProbeOrder();
    }

    public function getMailError(): bool
    {
        return false;
    }

    /** @return array<int, mixed> */
    public function getAlsoBoughtTheseProducts(): array
    {
        return [];
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class ThankYouProbeOrder
{
    public object $oxorder__oxordernr;

    public function __construct()
    {
        $this->oxorder__oxordernr = (object) ['value' => '342'];
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class ThankYouProbeShop
{
    /** Distinctive on purpose: the plain order number also occurs elsewhere on the page. */
    public const NAME = 'ProbeShopNameZQX';

    public object $oxshops__oxname;

    public function __construct()
    {
        $this->oxshops__oxname = (object) ['value' => self::NAME];
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * The thank-you page's payment notice.
 *
 * payment-base overrides `checkout_thankyou_info`. Whether that override lands
 * is a property of the shop's template chain rather than of the file — several
 * modules extend this template — so this renders the real thing through the
 * shop's own renderer.
 */
#[Group('integration')]
class ThankYouNoticeTemplateTest extends IntegrationTestCase
{
    private const NOTICE = 'Your payment is being processed.';

    public function testTheNoticeIsRenderedInsideTheThankYouInfo(): void
    {
        $output = $this->renderThankYou([self::NOTICE]);

        $this->assertStringContainsString(self::NOTICE, $output);
        $this->assertStringContainsString('id="paymentNotice"', $output);
    }

    /**
     * The point of the change: a payment still settling is a notice, not the
     * red alert the shop paints display errors as.
     */
    public function testTheNoticeIsNotStyledAsAnError(): void
    {
        $output = $this->renderThankYou([self::NOTICE]);

        $noticeMarkup = $this->noticeElement($output);

        $this->assertStringContainsString('alert-info', $noticeMarkup);
        $this->assertStringNotContainsString('alert-danger', $noticeMarkup);
    }

    /**
     * It has to come before the thank-you wording: "your payment is being
     * processed" changes how the rest of that text should be read.
     */
    public function testTheNoticeComesBeforeTheThankYouWording(): void
    {
        $output = $this->renderThankYou([self::NOTICE]);

        // Both searches are scoped to the thank-you container: the shop name is
        // also printed in the page header, and the order number occurs
        // elsewhere in the markup too.
        $page = strpos($output, 'id="thankyouPage"');
        $this->assertNotFalse($page, 'the thank-you block must have rendered');

        $notice = strpos($output, 'id="paymentNotice"', $page);
        $thanks = strpos($output, ThankYouProbeShop::NAME, $page);

        $this->assertNotFalse($notice, 'the notice must be inside the thank-you block');
        $this->assertNotFalse($thanks, 'the thank-you wording must still be rendered');
        $this->assertLessThan($thanks, $notice);
    }

    public function testTheOrdinaryThankYouPageIsUntouched(): void
    {
        $output = $this->renderThankYou([]);

        $this->assertStringNotContainsString('id="paymentNotice"', $output);
        // Paired positive: the page really rendered, it just has no notice.
        $this->assertStringContainsString(ThankYouProbeShop::NAME, $output);
    }

    public function testEveryQueuedNoticeIsRendered(): void
    {
        $output = $this->renderThankYou(['first notice', 'second notice']);

        $this->assertStringContainsString('first notice', $output);
        $this->assertStringContainsString('second notice', $output);
    }

    private function noticeElement(string $output): string
    {
        $start = strpos($output, 'id="paymentNotice"');
        $this->assertNotFalse($start, 'the notice element must be present');

        return substr($output, max(0, $start - 120), 400);
    }

    /**
     * @param array<int, string> $notices
     */
    private function renderThankYou(array $notices): string
    {
        $bridge = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class);

        $output = $bridge->getTemplateRenderer()->renderTemplate(
            'page/checkout/thankyou.html.twig',
            [
                'oView' => new ThankYouProbeView($notices),
                'oxcmp_shop' => new ThankYouProbeShop(),
                'oxcmp_user' => new ThankYouProbeShop(),
            ]
        );

        // A shop without a usable frontend theme answers with the template name
        // instead of markup; a silent pass would claim the override was verified
        // when nothing rendered.
        $this->assertNotSame(
            'page/checkout/thankyou.html.twig',
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );

        return $output;
    }
}
