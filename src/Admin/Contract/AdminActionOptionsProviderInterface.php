<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Contract;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Optional second interface of a {@see PaymentPanelProviderInterface}: declares what the provider accepts as the
 * reason of a refund or void and as the description of a refund, per contract. A provider that does not implement it
 * accepts free text, as the classic Payment tab does. Deliberately outside the GraphQL namespaces: a provider
 * implements it without graphql installed.
 *
 * @since 1.7.0
 */
interface AdminActionOptionsProviderInterface
{
    public function actionOptions(PaymentContractInterface $contract): AdminActionOptions;
}
