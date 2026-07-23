<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Provider-agnostic port for the "Use iframe instead of checkout button"
 * setting (blPaymentBaseUseIframe).
 *
 * PSP modules depend on this interface to decide whether to embed their
 * payment UI inline (iframe) instead of rendering a redirect button. The
 * interface names no provider and carries no provider-specific concept.
 */
interface IframeCheckoutSettingsInterface
{
    /**
     * True when the merchant enabled inline iframe checkout for the shop.
     */
    public function isEnabled(): bool;
}
