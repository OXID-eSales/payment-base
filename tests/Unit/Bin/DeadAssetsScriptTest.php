<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Bin;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 17 DC-5c: `bin/dead-assets` is the non-PHP half of the dead-code gate. It fails when a language key,
 * a Twig template or an asset file has no production reference. Run against a throw-away module root.
 */
final class DeadAssetsScriptTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = sys_get_temp_dir() . '/pb-dead-assets-' . bin2hex(random_bytes(6));
        $this->write('src/Panel.php', "<?php\n\$label = 'USED_KEY';\n\$p = 'DYNAMIC_PREFIX_' . \$state;\n");
        $this->write('metadata.php', "<?php\nreturn ['logo' => 'assets/img/logo.png'];\n");
        $this->write('views/admin_twig/en/lang.php', $this->langFile(['USED_KEY', 'DYNAMIC_PREFIX_ONE']));
        $this->write('views/twig/admin/tab.html.twig', "{% include '@oe_payment_base/admin/part.html.twig' %}\n");
        $this->write('views/twig/admin/part.html.twig', "<p>{{ 'USED_KEY'|translate }}</p>\n");
        $this->write('assets/img/logo.png', 'png');
        $this->write('src/Tab.php', "<?php\n\$template = 'admin/tab.html.twig';\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->moduleRoot);
    }

    public function testACleanModulePasses(): void
    {
        [$exitCode] = $this->runScript();

        $this->assertSame(0, $exitCode);
    }

    public function testAKeyBuiltInTwigWithATildeCountsAsReferenced(): void
    {
        $this->write('views/twig/admin/part.html.twig', "{{ translate({ ident: \"DYNAMIC_PREFIX_\" ~ reason }) }} {{ 'USED_KEY'|translate }}\n");
        $this->write('views/admin_twig/en/lang.php', $this->langFile(['USED_KEY', 'DYNAMIC_PREFIX_ONE']));
        $this->write('src/Panel.php', "<?php\n\$label = 'USED_KEY';\n");

        [$exitCode] = $this->runScript();

        $this->assertSame(0, $exitCode);
    }

    public function testAnUnusedLanguageKeyFails(): void
    {
        $this->write('views/admin_twig/en/lang.php', $this->langFile(['USED_KEY', 'NEVER_REFERENCED_KEY']));

        [$exitCode, $output] = $this->runScript();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NEVER_REFERENCED_KEY', $output);
    }

    public function testAnUnincludedTemplateFails(): void
    {
        $this->write('views/twig/admin/orphan.html.twig', "<p>orphan</p>\n");

        [$exitCode, $output] = $this->runScript();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('admin/orphan.html.twig', $output);
    }

    public function testAnUnreferencedAssetFails(): void
    {
        $this->write('assets/js/stray.js', 'x');

        [$exitCode, $output] = $this->runScript();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('assets/js/stray.js', $output);
    }

    public function testAThemeTemplateExtensionNeedsNoReference(): void
    {
        $this->write('views/twig/extensions/themes/default/page/order.html.twig', "{% extends 'page/order.html.twig' %}\n");

        [$exitCode] = $this->runScript();

        $this->assertSame(0, $exitCode);
    }

    public function testAnAllowListedItemIsKeptWithItsReason(): void
    {
        $this->write('views/twig/admin/shared.html.twig', "<p>used by a provider module</p>\n");
        $this->write('dead-assets.allow', "# provider modules include it\nviews/twig/admin/shared.html.twig  # VR-17: used by stripe and mollie\n");

        [$exitCode] = $this->runScript();

        $this->assertSame(0, $exitCode);
    }

    /** @return array{0: int, 1: string} */
    private function runScript(): array
    {
        $script = dirname(__DIR__, 3) . '/bin/dead-assets';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->moduleRoot) . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /** @param list<string> $keys */
    private function langFile(array $keys): string
    {
        $lines = array_map(static fn (string $key): string => "    '$key' => 'text',", $keys);

        return "<?php\n\$aLang = [\n    'charset' => 'UTF-8',\n" . implode("\n", $lines) . "\n];\n";
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->moduleRoot . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($path . '/' . $entry) ? $this->removeTree($path . '/' . $entry) : unlink($path . '/' . $entry);
        }
        rmdir($path);
    }
}
