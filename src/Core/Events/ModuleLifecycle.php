<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Core\Events;

/**
 * Module lifecycle hooks for `oe_payment_base`.
 *
 * Intentionally a no-op:
 *
 * - Database schema is owned by Doctrine migrations under
 *   `migration/data/`. They run with the shop's migration wrapper
 *   (`vendor/bin/oe-eshop-db_migrate migrations:migrate`), and after every
 *   `composer install` / `update` when the project's composer.json calls
 *   `oe-payment-base-migrate` (see README, "Migrations").
 * - Services and the admin tab are registered declaratively
 *   (services.yaml + menu.xml) — no imperative wiring on activation.
 * - Deactivation removes the admin tab automatically via OXID's menu
 *   loader; no cleanup is needed.
 */
class ModuleLifecycle
{
    public static function onActivate(): void
    {
    }

    public static function onDeactivate(): void
    {
    }
}
