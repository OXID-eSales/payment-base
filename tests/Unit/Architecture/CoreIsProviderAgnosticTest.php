<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * VR-24 H6: payment-base is the core every provider shares, so its code names no provider. What a provider owns
 * (its payment rows, its secret shapes) is contributed by the provider through a tag.
 *
 * Comments and doc blocks are exempt: they may explain a rule with the provider it came from. Everything else
 * (identifiers, string literals) is code and must not carry a provider name.
 */
final class CoreIsProviderAgnosticTest extends TestCase
{
    private const PROVIDER_NAME = '/(?<![a-z])(stripe|mollie|paypal)(?![a-z])/i';

    public function testSourceCodeMentionsNoProvider(): void
    {
        self::assertSame([], $this->providerMentionsInCode(dirname(__DIR__, 3) . '/src'));
    }

    public function testTheCheckSeesAProviderNameInCodeButNotInAComment(): void
    {
        $source = "<?php\n// Stripe in a comment\n/** PayPal in a doc block */\n\$x = 'oe_payments_mollie';\nconst MOLLIE_KEY = 1;\n";

        $mentions = $this->providerMentionsInSource($source);

        self::assertSame(["4: 'oe_payments_mollie'", '5: MOLLIE_KEY'], $mentions);
    }

    /**
     * @return list<string>
     */
    private function providerMentionsInCode(string $directory): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->providerMentionsInSource((string) file_get_contents($file->getPathname())) as $mention) {
                $found[] = $file->getPathname() . ':' . $mention;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string> "line: token"
     */
    private function providerMentionsInSource(string $source): array
    {
        $mentions = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match(self::PROVIDER_NAME, $token[1]) === 1) {
                $mentions[] = $token[2] . ': ' . trim($token[1]);
            }
        }

        return $mentions;
    }
}
