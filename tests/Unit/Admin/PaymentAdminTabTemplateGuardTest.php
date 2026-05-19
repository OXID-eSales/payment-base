<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 05 (2026-05-19) — guards the load-bearing parts of the
 * shared Payment-tab template.
 *
 * The template `payment_admin_tab.html.twig` carries the busy/spinner
 * overlay machinery on behalf of every PSP module that drops a panel
 * into the shared admin tab. These assertions verify that the markup,
 * CSS, and JS that make the overlay work are present in the template
 * source — so a future contributor who refactors / minifies / extracts
 * cannot silently break the overlay for ALL PSPs at once.
 *
 * Each assertion targets a single load-bearing element with a clear
 * remediation pointer in the failure message.
 */
class PaymentAdminTabTemplateGuardTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/views/twig/admin/payment_admin_tab.html.twig';
        self::assertFileExists($path, 'payment_admin_tab.html.twig must exist.');
        $this->source = (string) file_get_contents($path);
    }

    public function testTemplateRendersWrapperWithBusyClassAtFirstPaint(): void
    {
        // Wrapper carries .pc-panel-busy server-side so the spinner is
        // visible from first paint; init() clears it on DOMContentLoaded.
        self::assertMatchesRegularExpression(
            '/class="pc-admin\s+pc-panel-busy"/',
            $this->source,
            'payment_admin_tab.html.twig must render the wrapper with '
            . '`pc-admin pc-panel-busy` so the spinner is visible at first paint. '
            . 'Sprint 05 moved this from stripe-side; do not regress it.'
        );
    }

    public function testTemplateRendersAriaBusyAttribute(): void
    {
        self::assertMatchesRegularExpression(
            '/aria-busy="true"/',
            $this->source,
            'payment_admin_tab.html.twig must include aria-busy="true" so '
            . 'screen readers announce the loading state at first paint.'
        );
    }

    public function testTemplateContainsSpinnerElement(): void
    {
        self::assertMatchesRegularExpression(
            '/class="pc-spinner"[^>]*role="status"/',
            $this->source,
            'payment_admin_tab.html.twig must include the spinner DOM '
            . 'element `<div class="pc-spinner" role="status" …>` — the '
            . 'busy-overlay CSS rule `.pc-panel-busy .pc-spinner { display: block }` '
            . 'depends on it.'
        );
    }

    public function testTemplateContainsBusyCssWithBlur(): void
    {
        // CSS rule that fades + blurs every child of the busy wrapper
        // except the spinner.
        self::assertMatchesRegularExpression(
            '/\.pc-admin\.pc-panel-busy\s*>\s*\*:not\(\.pc-spinner\)[^{]*\{[^}]*filter:\s*blur/',
            $this->source,
            'payment_admin_tab.html.twig must include the busy-overlay CSS '
            . 'with `filter: blur(...)` for non-spinner children. The blur '
            . 'is what visually separates the loading state from the idle state.'
        );
    }

    public function testTemplateContainsEnterBusyFunction(): void
    {
        self::assertMatchesRegularExpression(
            '/function\s+enterBusy\s*\(/',
            $this->source,
            'payment_admin_tab.html.twig must define `enterBusy(panel)` — '
            . 'the JS function that adds .pc-panel-busy + aria-busy to '
            . 'the wrapper on action-form submit and on cross-frame nav.'
        );
    }

    public function testTemplateContainsCrossFrameNavHook(): void
    {
        self::assertStringContainsString(
            "window.parent",
            $this->source,
            'payment_admin_tab.html.twig must reference window.parent so '
            . 'it can bind the sibling `list` frame click listener. '
            . 'Without it, inter-order navigation (admin clicks a '
            . 'different order while on Payment tab) shows no spinner '
            . 'during the server round-trip.'
        );
        self::assertStringContainsString(
            "frames['list']",
            $this->source,
            'cross-frame nav hook must target the OXID admin `list` frame.'
        );
    }

    public function testTemplateListensForPaymentActionFormSubmits(): void
    {
        // PSP-agnostic class — Stripe, PayPal, future PSPs mark their
        // action forms with this same class so the shared JS reaches them.
        self::assertStringContainsString(
            'js-payment-action-form',
            $this->source,
            'payment_admin_tab.html.twig must listen for submits on '
            . '`.js-payment-action-form` — the PSP-agnostic class that '
            . 'PSP panels mark their action forms with so the shared '
            . 'overlay JS picks them up.'
        );
    }
}
