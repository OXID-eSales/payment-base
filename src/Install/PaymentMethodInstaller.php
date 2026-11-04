<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Install;

use Doctrine\DBAL\Query\QueryBuilder;
use OxidEsales\Eshop\Application\Model\Payment as EshopModelPayment;
use OxidEsales\Eshop\Core\Model\BaseModel as EshopBaseModel;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use PDO;

/**
 * Creates a provider's payment method rows in `oxpayments` and assigns them to all active delivery
 * sets, but only the ones that do not exist yet. Idempotent: safe to call on every activation, and
 * admin edits of an existing row survive.
 *
 * A module's activation event cannot take constructor injection, so the provider builds this
 * installer with the query builder factory and hands it its own definitions.
 *
 * Not final on purpose: the protected steps are seams for the integration test, which runs them
 * against a throwaway payment id instead of a live row.
 */
class PaymentMethodInstaller
{
    public function __construct(
        private readonly QueryBuilderFactoryInterface $queryBuilderFactory,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $definitionsByPaymentId payment id => definition
     *        (`defaulton`, `constraints`, `descriptions` per language abbreviation)
     */
    public function ensurePaymentMethods(array $definitionsByPaymentId): void
    {
        foreach ($definitionsByPaymentId as $paymentId => $definitions) {
            /** @var EshopModelPayment $paymentMethod */
            $paymentMethod = oxNew(EshopModelPayment::class);
            if ($paymentMethod->load($paymentId)) {
                continue; // already exists: do not re-create or clobber admin edits
            }
            $this->createPaymentMethod($paymentId, $definitions);
            $this->assignPaymentToActiveDeliverySets($paymentId);
        }
    }

    /**
     * @param array<string, mixed> $definitions
     */
    protected function createPaymentMethod(string $paymentId, array $definitions): void
    {
        /** @var EshopModelPayment $paymentModel */
        $paymentModel = oxNew(EshopModelPayment::class);
        $paymentModel->setId($paymentId);

        /** @var array<int|string, int> $iso2LanguageId */
        $iso2LanguageId = array_flip($this->getLanguageIds());

        /** @var array{oxfromamount?: float, oxtoamount?: float, oxaddsumtype?: string} $constraints */
        $constraints = is_array($definitions['constraints'] ?? null) ? $definitions['constraints'] : [];

        $paymentModel->assign([
            'oxactive'     => (bool) ($definitions['defaulton'] ?? false),
            'oxfromamount' => (float) ($constraints['oxfromamount'] ?? 0),
            'oxtoamount'   => (float) ($constraints['oxtoamount'] ?? 1000000),
            'oxaddsumtype' => (string) ($constraints['oxaddsumtype'] ?? 'abs'),
        ]);
        $paymentModel->save();

        /** @var array<string, array{desc?: string, longdesc?: string}> $descriptions */
        $descriptions = is_array($definitions['descriptions'] ?? null) ? $definitions['descriptions'] : [];
        foreach ($descriptions as $langAbbr => $data) {
            if (!isset($iso2LanguageId[$langAbbr])) {
                continue;
            }
            $paymentModel->loadInLang($iso2LanguageId[$langAbbr], $paymentId);
            $paymentModel->assign([
                'oxdesc'     => $data['desc'] ?? '',
                'oxlongdesc' => $data['longdesc'] ?? '',
            ]);
            $paymentModel->save();
        }
    }

    protected function assignPaymentToActiveDeliverySets(string $paymentId): void
    {
        foreach ($this->getActiveDeliverySetIds() as $deliverySetId) {
            /** @var EshopBaseModel $object2Payment */
            $object2Payment = oxNew(EshopBaseModel::class);
            $object2Payment->init('oxobject2payment');
            $object2Payment->assign([
                'oxpaymentid' => $paymentId,
                'oxobjectid'  => $deliverySetId,
                'oxtype'      => 'oxdelset',
            ]);
            $object2Payment->save();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getActiveDeliverySetIds(): array
    {
        /** @var QueryBuilder $qb */
        $qb = $this->queryBuilderFactory->create();
        $stmt = $qb->select('oxid')->from('oxdeliveryset')->where('oxactive = 1')->execute();
        /** @var array<int, array{oxid: string}> $rows */
        $rows = is_object($stmt) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($rows as $row) {
            $out[$row['oxid']] = $row['oxid'];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    protected function getLanguageIds(): array
    {
        /** @var array<int, string> $ids */
        $ids = EshopRegistry::getLang()->getLanguageIds();

        return $ids;
    }
}
