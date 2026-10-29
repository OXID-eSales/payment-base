<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\ReplacedAttempts;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2g — the history of replaced provider attempts records what
 * happened to money that arrived on one of them (refund / release), once.
 */
final class ReplacedAttemptsTest extends TestCase
{
    public function testWithLateMoney_RecordsTheOutcomeOnThatAttempt(): void
    {
        $history = ReplacedAttempts::withAttempt(null, 'stripe', 'cs_1', 'oe_payments_stripe_wallet', AttemptClosure::Closed);
        $history = ReplacedAttempts::withAttempt($history, 'mollie', 'tr_1', '', AttemptClosure::Open);

        $updated = ReplacedAttempts::withLateMoney($history, 1, 'refunded:re_1');

        self::assertArrayNotHasKey('lateMoney', $updated[0]);
        self::assertSame('refunded:re_1', $updated[1]['lateMoney']);
        self::assertNotSame('', $updated[1]['lateMoneyAt']);
    }

    public function testLateMoneyOf_AnswersTheRecordedOutcomeOrNull(): void
    {
        $history = ReplacedAttempts::withAttempt(null, 'stripe', 'cs_1', '', AttemptClosure::Closed);

        self::assertNull(ReplacedAttempts::lateMoneyIn($history, 0));
        self::assertSame('released', ReplacedAttempts::lateMoneyIn(ReplacedAttempts::withLateMoney($history, 0, 'released'), 0));
    }

    public function testIndexOf_FindsTheAttemptByItsProviderOrderId(): void
    {
        $history = ReplacedAttempts::withAttempt(null, 'stripe', 'cs_1', '', AttemptClosure::Closed);
        $history = ReplacedAttempts::withAttempt($history, 'mollie', 'tr_1', '', AttemptClosure::Open);

        self::assertSame(1, ReplacedAttempts::indexOf($history, 'tr_1'));
        self::assertNull(ReplacedAttempts::indexOf($history, 'tr_unknown'));
    }

    public function testWithLateMoney_ForAnUnknownAttempt_Fails(): void
    {
        $this->expectException(\OutOfRangeException::class);
        ReplacedAttempts::withLateMoney([], 0, 'released');
    }
}
