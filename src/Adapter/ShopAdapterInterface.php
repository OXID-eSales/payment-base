<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Shop adapter interface for shop-specific operations.
 *
 * Provides abstraction layer for shop operations like translations, configuration,
 * session management, etc. This allows the Payment Component to remain
 * platform-agnostic while working with different shop systems (OXID, Shopware, Magento, etc.).
 *
 * Following the same pattern as PaymentAdapterInterface for consistency.
 *
 * @since 1.0.0
 */
interface ShopAdapterInterface
{
    /**
     * Translate a language identifier to the current shop language.
     *
     * Replaces deprecated Registry::getLang()->translateString().
     *
     * @param string $languageConstant Language constant/identifier (e.g., 'OSC_STRIPE_CARD_PAYMENT')
     * @param string|null $languageAbbr Optional language abbreviation (e.g., 'en', 'de'). Uses current shop language if null.
     * @return string Translated string or the constant itself if translation not found
     */
    public function translateString(string $languageConstant, ?string $languageAbbr = null): string;

    /**
     * Get current shop language abbreviation (e.g., 'en', 'de', 'fr').
     *
     * @return string Language abbreviation
     */
    public function getCurrentLanguageAbbr(): string;

    /**
     * Get current shop ID.
     *
     * @return string Shop identifier
     */
    public function getShopId(): string;

    /**
     * Get current shop base URL.
     *
     * @return string Shop base URL
     */
    public function getShopUrl(): string;

    /**
     * Get shop name/title.
     *
     * @return string Shop name
     */
    public function getShopName(): string;

    /**
     * Get shop currency code (e.g., 'EUR', 'USD', 'GBP').
     *
     * @return string Currency code
     */
    public function getShopCurrency(): string;

    /**
     * Check if the SHOP is in test/development mode (e.g. OXID's blDebugMode).
     *
     * Sprint 133 (F20): this is the shop's own dev flag, NOT the payment
     * provider's test/live mode — providers expose that separately through their
     * own configuration service. The two are independent: a live shop can run
     * with debug on, and a staging shop can hold live PSP credentials. Do not
     * use this method to decide which API keys to use.
     *
     * @return bool True if the shop is in test/development mode
     */
    public function isTestMode(): bool;

    /**
     * Get shop adapter name/type (e.g., 'oxid', 'shopware', 'magento').
     *
     * @return string Adapter identifier
     */
    public function getAdapterName(): string;
}
