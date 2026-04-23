<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Admin\Panel;

/**
 * Immutable DTO returned by every panel provider.
 *
 * The controller renders `templatePath` inside the shared wrapper,
 * exposing `viewData` to the template. `providerKey` is stamped as a
 * `data-provider` attribute so the wrapper CSS can scope small
 * PSP-specific paint jobs without each module shipping its own stylesheet.
 */
final readonly class PaymentPanelRenderable
{
    /**
     * @param array<string, mixed> $viewData
     */
    public function __construct(
        public string $templatePath,
        public array $viewData,
        public string $providerKey,
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtraViewData(array $extra): self
    {
        return new self(
            templatePath: $this->templatePath,
            viewData: array_merge($this->viewData, $extra),
            providerKey: $this->providerKey,
        );
    }
}
