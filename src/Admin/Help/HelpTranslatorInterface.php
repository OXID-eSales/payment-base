<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Help;

/**
 * Translates an admin lang ident of this module into a language the caller names, independent of the shop's
 * current admin language (the headless admin API has none of its own).
 *
 * @since 1.7.0
 */
interface HelpTranslatorInterface
{
    /** The ident itself when it is unknown; English for a language without a file. */
    public function translate(string $ident, string $language): string;
}
