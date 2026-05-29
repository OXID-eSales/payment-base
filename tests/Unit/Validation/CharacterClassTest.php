<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation;

use OxidEsales\PaymentBase\Validation\CharacterClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterClass::class)]
class CharacterClassTest extends TestCase
{
    // RED test 1
    public function testUniversalRejectFindsTab(): void
    {
        $result = CharacterClass::hasUniversalReject("a\tb");

        $this->assertSame("\t", $result);
    }

    // RED test 2
    public function testUniversalRejectFindsLineBreaks(): void
    {
        $this->assertSame("\n", CharacterClass::hasUniversalReject("a\nb"));
        $this->assertSame("\r", CharacterClass::hasUniversalReject("a\rb"));
    }

    // RED test 3
    public function testUniversalRejectFindsNullByte(): void
    {
        $result = CharacterClass::hasUniversalReject("a\0b");

        $this->assertSame("\0", $result);
    }

    // RED test 4
    public function testUniversalRejectFindsZeroWidthSpace(): void
    {
        $result = CharacterClass::hasUniversalReject("a\u{200B}b");

        $this->assertSame("\u{200B}", $result);
    }

    // RED test 5
    public function testUniversalRejectPassesPlainAscii(): void
    {
        $result = CharacterClass::hasUniversalReject("O'Connor");

        $this->assertNull($result);
    }

    public function testUniversalRejectPassesUnicodeName(): void
    {
        $result = CharacterClass::hasUniversalReject('Köln');

        $this->assertNull($result);
    }

    public function testUniversalRejectFindsC1Control(): void
    {
        // U+0080 in UTF-8 is \xC2\x80 (the byte sequence for the first C1 control)
        $c1Char = "\xC2\x80";
        $result = CharacterClass::hasUniversalReject("a{$c1Char}b");

        $this->assertSame($c1Char, $result);
    }

    public function testUniversalRejectFindsSoftHyphen(): void
    {
        $result = CharacterClass::hasUniversalReject("a\u{00AD}b");

        $this->assertSame("\u{00AD}", $result);
    }

    public function testMatchesClassUnicodeLetters(): void
    {
        $this->assertTrue(CharacterClass::matchesClass('A', 'UNICODE_LETTERS'));
        $this->assertTrue(CharacterClass::matchesClass('ö', 'UNICODE_LETTERS'));
        $this->assertFalse(CharacterClass::matchesClass('1', 'UNICODE_LETTERS'));
        $this->assertFalse(CharacterClass::matchesClass(' ', 'UNICODE_LETTERS'));
    }

    public function testMatchesClassLetters(): void
    {
        $this->assertTrue(CharacterClass::matchesClass('A', 'LETTERS'));
        $this->assertTrue(CharacterClass::matchesClass('z', 'LETTERS'));
        $this->assertFalse(CharacterClass::matchesClass('ö', 'LETTERS'));
        $this->assertFalse(CharacterClass::matchesClass('1', 'LETTERS'));
    }

    public function testMatchesClassNumbers(): void
    {
        $this->assertTrue(CharacterClass::matchesClass('1', 'NUMBERS'));
        $this->assertFalse(CharacterClass::matchesClass('a', 'NUMBERS'));
    }

    public function testMatchesClassSpaces(): void
    {
        $this->assertTrue(CharacterClass::matchesClass(' ', 'SPACES'));
        $this->assertFalse(CharacterClass::matchesClass('a', 'SPACES'));
        $this->assertFalse(CharacterClass::matchesClass("\t", 'SPACES'));
    }
}
