<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Panel;

use RuntimeException;

/**
 * A provider's {@see \OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface::handleAction()} throws this
 * when it refuses an admin action for a reason the admin should read (an amount below the provider's minimum, a
 * capture window that closed ...). The admin API answers `ValidationError provider_refused` carrying the sentence;
 * the classic Payment tab shows it through the classic feedback and does not fail.
 *
 * The sentence is shown to the admin as it is: one short, human sentence - no ids, no provider payloads, no secrets.
 * It is cut at 255 characters and loses control characters here. `refusalCode` is the provider's own machine code
 * (lower-case letters, digits, `_`), logged with the refusal.
 *
 * Set `feedbackRecorded` when the provider has already put the message on its classic feedback channel (Stripe's and
 * Mollie's session feedback): the classic tab then does not show it a second time.
 *
 * @since 1.7.0
 */
final class AdminActionRefusedException extends RuntimeException
{
    private const MAX_MESSAGE_LENGTH = 255;
    private const GENERIC_CODE = 'refused';
    private const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]+/u';

    private readonly string $refusalCode;

    public function __construct(string $refusalCode, string $message, private readonly bool $feedbackRecorded = false)
    {
        $this->refusalCode = preg_match(self::CODE_PATTERN, $refusalCode) === 1 ? $refusalCode : self::GENERIC_CODE;
        $sentence = preg_replace(self::CONTROL_CHARACTERS, ' ', $message) ?? '';
        parent::__construct(mb_substr($sentence, 0, self::MAX_MESSAGE_LENGTH));
    }

    public function refusalCode(): string
    {
        return $this->refusalCode;
    }

    public function feedbackRecorded(): bool
    {
        return $this->feedbackRecorded;
    }
}
