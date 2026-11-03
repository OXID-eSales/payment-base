<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\AtRisk\PaymentAtRisk;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskQueryInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentAtRisk;
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

    private const DEFAULT_AT_RISK_LIMIT = 100;

    public function __construct(
        private readonly AdminStateHelpReaderInterface $stateHelp,
        private readonly PaymentAtRiskQueryInterface $atRisk,
    ) {
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

    /**
     * VR-23 T1: the open "payment at risk" flags of orders, newest first - the data behind the order-list filter.
     *
     * @return list<AdminPaymentAtRisk>
     */
    #[Query]
    #[Right(PaymentRightsProvider::VIEW)]
    public function adminPaymentsAtRisk(?int $limit = null): array
    {
        return array_map(
            static fn (PaymentAtRisk $flag): AdminPaymentAtRisk => new AdminPaymentAtRisk($flag),
            $this->atRisk->openForOrders($limit ?? self::DEFAULT_AT_RISK_LIMIT),
        );
    }
}
