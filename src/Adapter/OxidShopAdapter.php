<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Core\Registry;
use RuntimeException;

/**
 * The one OXID implementation of {@see ShopAdapterInterface} (VR-25 D0).
 *
 * Stripe, Mollie and PayPal each bound this interface id to a copy of their own;
 * the last services.yaml merged won, so one provider could run on another's
 * adapter. payment-base owns the binding now and the providers bind nothing.
 *
 * It answers shop questions only. Whether a provider runs in test or live mode
 * is that provider's configuration, not the shop's: {@see isTestMode()} is the
 * shop's debug flag, as the interface documents.
 *
 * @since 1.6.0
 */
class OxidShopAdapter implements ShopAdapterInterface
{
    public function translateString(string $languageConstant, ?string $languageAbbr = null): string
    {
        $language = Registry::getLang();
        $languageId = $languageAbbr === null ? null : $this->languageIdByAbbr($languageAbbr);

        /** @var string|array<string> $result */
        $result = $languageId === null
            ? $language->translateString($languageConstant)
            : $language->translateString($languageConstant, $languageId);

        return is_array($result) ? (string) reset($result) : $result;
    }

    public function getCurrentLanguageAbbr(): string
    {
        return (string) Registry::getLang()->getLanguageAbbr();
    }

    public function getShopId(): string
    {
        return (string) Registry::getConfig()->getShopId();
    }

    public function getShopUrl(): string
    {
        return (string) Registry::getConfig()->getCurrentShopUrl();
    }

    /**
     * Empty when the merchant set none — never a guessed brand.
     */
    public function getShopName(): string
    {
        return ShopName::of(Registry::getConfig()->getActiveShop());
    }

    /**
     * @throws RuntimeException when the shop currency cannot be determined; a payment must not
     *         guess one (a CHF amount billed as EUR).
     */
    public function getShopCurrency(): string
    {
        return ShopCurrency::nameOf(Registry::getConfig()->getActShopCurrencyObject(), 'active shop currency');
    }

    public function isTestMode(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blDebugMode');
    }

    public function getAdapterName(): string
    {
        return 'oxid';
    }

    private function languageIdByAbbr(string $abbr): ?int
    {
        foreach (Registry::getLang()->getLanguageArray() as $language) {
            if (is_object($language) && ($language->abbr ?? null) === $abbr) {
                return isset($language->id) ? (int) $language->id : null;
            }
        }

        return null;
    }
}
