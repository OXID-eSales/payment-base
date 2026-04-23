<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Core\Events;

/**
 * Module lifecycle hooks for `oe_payment_component`.
 *
 * Intentionally a no-op:
 *
 * - Database schema is owned by Doctrine migrations under
 *   `migration/data/`. CI invokes them via `doctrine-migrations
 *   migrate --configuration=vendor/oxid-esales/payment-component/migration/migrations.yml`
 *   as part of the existing shop-install workflow. Local devs run the
 *   same command or `bin/oe-console oe:migrations:migrate` depending
 *   on the project.
 * - Services and the admin tab are registered declaratively
 *   (services.yaml + menu.xml) — no imperative wiring on activation.
 * - Deactivation removes the admin tab automatically via OXID's menu
 *   loader; no cleanup is needed.
 */
final class ModuleLifecycle
{
    public static function onActivate(): void
    {
    }

    public static function onDeactivate(): void
    {
    }
}
