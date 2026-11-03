<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Stock;

use RuntimeException;

final class ThrowingOrderArticle extends RecordingOrderArticle
{
    public function __construct(string $articleRowId)
    {
        parent::__construct($articleRowId, 1.0, 0);
    }

    public function updateArticleStock($addAmount, $allowNegativeStock = false)
    {
        throw new RuntimeException('stock write failed');
    }
}
