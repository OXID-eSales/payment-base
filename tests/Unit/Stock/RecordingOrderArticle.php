<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Stock;

use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\Eshop\Core\Field;

/**
 * An order article whose stock side effect is recorded instead of hitting the DB; the magic columns the
 * service reads are plain Field values, as on a loaded model.
 */
class RecordingOrderArticle extends OrderArticle
{
    /** @var list<array{0: float, 1: bool}> */
    public array $stockCalls = [];

    public function __construct(private readonly string $articleRowId, float $amount, int $storno)
    {
        // Intentionally does NOT call parent::__construct() - no OXID bootstrap needed.
        $this->oxorderarticles__oxamount = new Field($amount, Field::T_RAW);
        $this->oxorderarticles__oxstorno = new Field($storno, Field::T_RAW);
    }

    public function getId()
    {
        return $this->articleRowId;
    }

    public function updateArticleStock($addAmount, $allowNegativeStock = false)
    {
        $this->stockCalls[] = [(float) $addAmount, (bool) $allowNegativeStock];
    }
}
