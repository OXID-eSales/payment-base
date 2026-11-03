<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

/**
 * Why a return leg was refused. For the log and the error page's wording; the caller (the
 * shopper's browser) is told no more than "this payment cannot be continued here".
 */
enum ReturnAccessRejection: string
{
    case ContractNotFound = 'contract_not_found';
    case ProviderMismatch = 'provider_mismatch';
    case TokenExpired = 'token_expired';
    case AccessDenied = 'access_denied';
}
