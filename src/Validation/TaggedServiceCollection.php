<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Thin, gettable wrapper around a Symfony `!tagged_iterator` argument.
 *
 * `ValidationApiController` runs under OXID's `cl=` dispatch and therefore
 * cannot receive tagged iterators via constructor injection; it resolves them
 * by service id from the container (`Container::get()`). A raw tagged iterator
 * is not directly gettable by id, so this holder is registered under the
 * `oe.payment_base.validation_guard_iterator` /
 * `oe.payment_base.validation_message_formatter_iterator` ids and yields the
 * tagged services on iteration.
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
class TaggedServiceCollection implements IteratorAggregate
{
    /** @var iterable<T> */
    private iterable $services;

    /**
     * @param iterable<T> $services
     */
    public function __construct(iterable $services)
    {
        $this->services = $services;
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        if ($this->services instanceof Traversable) {
            return $this->services;
        }

        return new ArrayIterator($this->services);
    }
}
