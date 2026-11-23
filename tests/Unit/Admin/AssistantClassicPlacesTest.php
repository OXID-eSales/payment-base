<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `assistant.classic-places.yaml`: the places of the classic Payment tab that the admin assistant names in the classic
 * admin ("How can I refund this order?"). The rules are the assistant's (admin-assistant docs/api/ui-map.md, section 4):
 * an id `classic.<name>`, a controller, `label.en`; here also German texts, help, the menu path and the payment right.
 */
final class AssistantClassicPlacesTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../assistant.classic-places.yaml';

    /** @return array<string, mixed> */
    private static function places(): array
    {
        $document = Yaml::parseFile(self::FILE);
        self::assertIsArray($document);
        self::assertIsArray($document['places'] ?? null, 'the file has a "places" map');

        return $document['places'];
    }

    public function testPlaces_TheFile_NamesRefundCaptureAndVoidOfThePaymentTab(): void
    {
        self::assertSame(
            ['classic.payment_base_refund', 'classic.payment_base_capture', 'classic.payment_base_void'],
            array_keys(self::places()),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function rightOfEachPlace(): iterable
    {
        yield 'refund' => ['classic.payment_base_refund', PaymentRightsProvider::REFUND];
        yield 'capture' => ['classic.payment_base_capture', PaymentRightsProvider::CAPTURE];
        yield 'void' => ['classic.payment_base_void', PaymentRightsProvider::CAPTURE];
    }

    #[DataProvider('rightOfEachPlace')]
    public function testPlace_EachEntry_KeepsTheRulesOfTheAssistant(string $id, string $right): void
    {
        $place = self::places()[$id];

        self::assertMatchesRegularExpression('/^classic\.[a-z0-9_]+$/', $id);
        self::assertSame('PaymentAdmin', $place['controller']);
        self::assertSame($right, $place['right']);
        foreach (['label', 'help', 'where'] as $text) {
            foreach (['en', 'de'] as $language) {
                self::assertNotSame('', trim((string) ($place[$text][$language] ?? '')), "$id: $text.$language");
            }
        }
        self::assertStringContainsString('Administer Orders › Orders', $place['where']['en']);
        self::assertStringContainsString('Bestellungen verwalten › Bestellungen', $place['where']['de']);
    }

    public function testController_PaymentAdmin_IsAControllerOfTheModule(): void
    {
        $metadata = (string) file_get_contents(__DIR__ . '/../../../metadata.php');

        self::assertMatchesRegularExpression("/'PaymentAdmin'\s*=>\s*PaymentAdminController::class/", $metadata);
    }
}
