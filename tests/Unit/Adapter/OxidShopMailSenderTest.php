<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\Email;
use OxidEsales\PaymentBase\Adapter\OxidShopMailSender;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use PHPUnit\Framework\TestCase;

final class FixtureShop extends Shop
{
    public function __construct(public readonly string $id)
    {
    }
}

final class TestableMailSender extends OxidShopMailSender
{
    public Email $email;

    /** @var list<int> */
    public array $loadedShops = [];

    public function __construct(private readonly bool $shopExists = true)
    {
        $this->email = new Email();
    }

    protected function newEmail(): Email
    {
        return $this->email;
    }

    protected function loadShop(int $shopId): ?Shop
    {
        $this->loadedShops[] = $shopId;

        return $this->shopExists ? new FixtureShop((string) $shopId) : null;
    }
}

/**
 * STRP-176 — the stored order mail goes out through core's Email, as core
 * would have sent it at order creation: same envelope, same content, the
 * order's shop's SMTP settings.
 */
final class OxidShopMailSenderTest extends TestCase
{
    public function testSend_CopiesEnvelopeAndContentAndSends(): void
    {
        $sender = new TestableMailSender();

        self::assertTrue($sender->send($this->mail(), 3));

        $calls = array_values(array_filter(
            $sender->email->calls,
            static fn (array $call): bool => $call[0] !== 'setSmtp'
        ));
        self::assertSame([
            ['setFrom', 'order@shop.test', 'My Shop'],
            ['setRecipient', 'jane@example.test', 'Jane Doe'],
            ['setReplyTo', 'order@shop.test', 'My Shop'],
            ['addCC', 'cc@shop.test', ''],
            ['addBCC', 'audit@shop.test', 'Audit'],
            ['setSubject', 'Your order (#42)'],
            ['setBody', '<p>Thank you</p>'],
            ['setAltBody', 'Thank you'],
            ['send'],
        ], $calls);
    }

    public function testSend_UsesTheSmtpSettingsOfTheOrdersShop(): void
    {
        // The mailer that rendered the mail set them in setMailParams(); a
        // fresh one starts with the active shop's, which may be another one.
        $sender = new TestableMailSender();

        $sender->send($this->mail(), 3);

        self::assertSame([3], $sender->loadedShops);
        self::assertSame('setSmtp', $sender->email->calls[0][0]);
        self::assertInstanceOf(FixtureShop::class, $sender->email->calls[0][1]);
        self::assertSame('3', $sender->email->calls[0][1]->id);
    }

    public function testSend_WhenTheShopCannotBeLoaded_KeepsTheActiveShopsSettings(): void
    {
        $sender = new TestableMailSender(shopExists: false);

        self::assertTrue($sender->send($this->mail(), 3));

        self::assertNotContains('setSmtp', array_column($sender->email->calls, 0));
    }

    public function testSend_ReportsWhatTheMailerReports(): void
    {
        $sender = new TestableMailSender();
        $sender->email->sendResult = false;

        self::assertFalse($sender->send($this->mail(), 1));
    }

    private function mail(): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: DeferredOrderMail::KIND_CUSTOMER,
            from: 'order@shop.test',
            fromName: 'My Shop',
            recipients: [['jane@example.test', 'Jane Doe']],
            replyTo: [['order@shop.test', 'My Shop']],
            cc: [['cc@shop.test', '']],
            bcc: [['audit@shop.test', 'Audit']],
            subject: 'Your order (#42)',
            body: '<p>Thank you</p>',
            altBody: 'Thank you',
        );
    }
}
