<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use RuntimeException;

/**
 * A stored row cannot be read as the object it holds. The repository refuses it: a contract whose state
 * or basket is damaged must not load as a new draft with an empty basket (sprint 147 AW).
 *
 * The message names table, row and column, never the content.
 */
final class UnreadableRowException extends RuntimeException
{
    public static function forColumn(string $table, string $rowId, string $column): self
    {
        return new self(sprintf('Row %s of %s cannot be read: column %s is damaged', $rowId, $table, $column));
    }
}
