<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Help;

/**
 * Reads the module's own `<dir>/<language>/payment_admin_lang.php` (the file the classic admin loads), so the
 * admin API can answer in English or German without touching the shop's language state.
 *
 * @since 1.7.0
 */
final class LangFileHelpTranslator implements HelpTranslatorInterface
{
    private const FALLBACK_LANGUAGE = 'en';
    private const LANGUAGE_PATTERN = '/^[a-z]{2}$/';
    private const FILE_NAME = 'payment_admin_lang.php';

    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    private readonly string $viewsDirectory;

    /**
     * @param string|null $viewsDirectory the `views/admin_twig` directory; the module's own when null (it is found
     *                                    from this file, so it holds wherever the module is installed or linked)
     */
    public function __construct(?string $viewsDirectory = null)
    {
        $this->viewsDirectory = $viewsDirectory ?? dirname(__DIR__, 3) . '/views/admin_twig';
    }

    public function translate(string $ident, string $language): string
    {
        $texts = $this->textsOf($this->knownLanguage($language));

        return $texts[$ident] ?? $this->textsOf(self::FALLBACK_LANGUAGE)[$ident] ?? $ident;
    }

    private function knownLanguage(string $language): string
    {
        $normalised = strtolower($language);
        if (preg_match(self::LANGUAGE_PATTERN, $normalised) !== 1 || !is_file($this->fileOf($normalised))) {
            return self::FALLBACK_LANGUAGE;
        }

        return $normalised;
    }

    /**
     * @return array<string, string>
     */
    private function textsOf(string $language): array
    {
        return $this->loaded[$language] ??= $this->load($this->fileOf($language));
    }

    /**
     * @return array<string, string>
     */
    private function load(string $file): array
    {
        $aLang = [];
        if (is_file($file)) {
            include $file;
        }

        return array_filter($aLang, 'is_string');
    }

    private function fileOf(string $language): string
    {
        return $this->viewsDirectory . '/' . $language . '/' . self::FILE_NAME;
    }
}
