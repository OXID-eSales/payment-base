<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Core;

use OxidEsales\PaymentBase\Eshop\Core\Email;
use OxidEsales\PaymentBase\Eshop\Core\Email_parent;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use PHPUnit\Framework\TestCase;

/**
 * STRP-176 — the order mail is rendered by core as always, but kept instead of
 * sent while the payment is still open.
 */
final class EmailCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        Email_parent::$calls = [];
    }

    public function testSend_WhenNotCapturing_DelegatesToParent(): void
    {
        $email = $this->renderedEmail();

        self::assertTrue($email->send());
        self::assertSame(['parent::send'], Email_parent::$calls);
    }

    public function testSend_WhenCapturing_RecordsEnvelopeAndContentAndSendsNothing(): void
    {
        $email = $this->renderedEmail();
        $email->captureAs(DeferredOrderMail::KIND_CUSTOMER);

        self::assertTrue($email->send());

        self::assertSame([], Email_parent::$calls);
        self::assertEquals([new DeferredOrderMail(
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
        )], $email->takeCapturedMails());
    }

    public function testSend_WhenCapturingWithoutRecipient_ReturnsFalseAndRecordsNothing(): void
    {
        // Core's send() answers false without a recipient; finalizeOrder()
        // turns that into ORDER_STATE_MAILINGERROR. Capturing must not hide it.
        $email = $this->renderedEmail();
        $email->recipients = [];
        $email->captureAs(DeferredOrderMail::KIND_CUSTOMER);

        self::assertFalse($email->send());
        self::assertSame([], $email->takeCapturedMails());
    }

    public function testCaptureAs_LabelsEachFollowingMailUntilTheNextLabel(): void
    {
        $email = $this->renderedEmail();

        $email->captureAs(DeferredOrderMail::KIND_CUSTOMER);
        $email->send();
        $email->captureAs(DeferredOrderMail::KIND_OWNER);
        $email->send();

        $kinds = array_map(static fn (DeferredOrderMail $mail): string => $mail->kind, $email->takeCapturedMails());
        self::assertSame([DeferredOrderMail::KIND_CUSTOMER, DeferredOrderMail::KIND_OWNER], $kinds);
    }

    public function testTakeCapturedMails_EmptiesTheBufferAndStopsCapturing(): void
    {
        $email = $this->renderedEmail();
        $email->captureAs(DeferredOrderMail::KIND_CUSTOMER);
        $email->send();

        $email->takeCapturedMails();

        self::assertSame([], $email->takeCapturedMails());
        $email->send();
        self::assertSame(['parent::send'], Email_parent::$calls);
    }

    private function renderedEmail(): Email
    {
        $email = new Email();
        $email->From = 'order@shop.test';
        $email->FromName = 'My Shop';
        $email->recipients = [['jane@example.test', 'Jane Doe']];
        $email->replies = [['order@shop.test', 'My Shop']];
        $email->cc = [['cc@shop.test', '']];
        $email->bcc = [['audit@shop.test', 'Audit']];
        $email->Subject = 'Your order (#42)';
        $email->Body = '<p>Thank you</p>';
        $email->AltBody = 'Thank you';

        return $email;
    }
}
