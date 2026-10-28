<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentStateHelp;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelpReaderInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * Read-only queries of the payment API that do not hang on an order. GraphQL mapping only. #[Right] without
 * #[Logged], like every admin operation, so that a refusal carries an errorCode.
 *
 * @since 1.7.0
 */
final class PaymentQueries
{
    private const DEFAULT_LANGUAGE = 'en';

    public function __construct(private readonly AdminStateHelpReaderInterface $stateHelp)
    {
    }

    /**
     * What every contract state means, in `language` (`en`, `de`; English otherwise), with the provider's own status
     * per state when `provider` names a module that contributes one.
     */
    #[Query]
    #[Right(PaymentRightsProvider::VIEW)]
    public function adminPaymentContractStateHelp(?string $provider = null, ?string $language = null): AdminPaymentStateHelp
    {
        return new AdminPaymentStateHelp($this->stateHelp->read($provider, $language ?? self::DEFAULT_LANGUAGE));
    }
}
