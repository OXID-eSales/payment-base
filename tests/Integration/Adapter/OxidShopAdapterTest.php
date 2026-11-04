<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Core\Config;
use OxidEsales\Eshop\Core\Language;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\OxidShopAdapter;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The one ShopAdapterInterface implementation (VR-25 D0): union of the Stripe, Mollie and PayPal copies.
 * Runs against the real shop; Config and Language are swapped into the Registry per test, in their own
 * process so nothing leaks into later tests.
 */
#[CoversClass(OxidShopAdapter::class)]
#[RunTestsInSeparateProcesses]
final class OxidShopAdapterTest extends TestCase
{
    private Config $originalConfig;
    private Language $originalLanguage;

    protected function setUp(): void
    {
        $this->originalConfig = Registry::getConfig();
        $this->originalLanguage = Registry::getLang();
    }

    protected function tearDown(): void
    {
        Registry::set(Config::class, $this->originalConfig);
        Registry::set(Language::class, $this->originalLanguage);
    }

    public function testImplementsTheSharedInterface(): void
    {
        $this->assertInstanceOf(ShopAdapterInterface::class, new OxidShopAdapter());
    }

    public function testAdapterNameIsOxid(): void
    {
        $this->assertSame('oxid', (new OxidShopAdapter())->getAdapterName());
    }

    public function testShopIdAndUrlComeFromTheConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getShopId')->willReturn(3);
        $config->method('getCurrentShopUrl')->willReturn('https://shop.test/');
        Registry::set(Config::class, $config);

        $adapter = new OxidShopAdapter();

        $this->assertSame('3', $adapter->getShopId());
        $this->assertSame('https://shop.test/', $adapter->getShopUrl());
    }

    public function testShopNameIsTheConfiguredOne(): void
    {
        $this->useShopNamed('Bäckerei Süd');

        $this->assertSame('Bäckerei Süd', (new OxidShopAdapter())->getShopName());
    }

    public function testShopNameIsEmptyWhenUnsetNotABrand(): void
    {
        $this->useShopNamed(null);

        $this->assertSame('', (new OxidShopAdapter())->getShopName());
    }

    public function testShopCurrencyIsTheActiveCurrencyName(): void
    {
        $currency = new stdClass();
        $currency->name = 'CHF';
        $this->useCurrency($currency);

        $this->assertSame('CHF', (new OxidShopAdapter())->getShopCurrency());
    }

    public function testShopCurrencyThrowsWhenCoreReturnsFalse(): void
    {
        $this->useCurrency(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to assume a currency');

        (new OxidShopAdapter())->getShopCurrency();
    }

    public function testIsTestModeIsTheShopDebugFlag(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getConfigParam')->willReturnMap([['blDebugMode', null, true]]);
        Registry::set(Config::class, $config);

        $this->assertTrue((new OxidShopAdapter())->isTestMode());
    }

    public function testLanguageAbbrComesFromTheLanguage(): void
    {
        $language = $this->createStub(Language::class);
        $language->method('getLanguageAbbr')->willReturn('de');
        Registry::set(Language::class, $language);

        $this->assertSame('de', (new OxidShopAdapter())->getCurrentLanguageAbbr());
    }

    public function testTranslatesInTheCurrentLanguage(): void
    {
        $language = $this->createMock(Language::class);
        $language->expects($this->once())->method('translateString')->with('KEY')->willReturn('Schlüssel');
        Registry::set(Language::class, $language);

        $this->assertSame('Schlüssel', (new OxidShopAdapter())->translateString('KEY'));
    }

    public function testTranslatesInTheLanguageOfTheGivenAbbreviation(): void
    {
        $german = (object) ['abbr' => 'de', 'id' => 0];
        $english = (object) ['abbr' => 'en', 'id' => 1];
        $language = $this->createMock(Language::class);
        $language->method('getLanguageArray')->willReturn(['skipped', $german, $english]);
        $language->expects($this->once())->method('translateString')->with('KEY', 1)->willReturn('Key');
        Registry::set(Language::class, $language);

        $this->assertSame('Key', (new OxidShopAdapter())->translateString('KEY', 'en'));
    }

    public function testTranslationArrayResultIsReducedToItsFirstEntry(): void
    {
        $language = $this->createStub(Language::class);
        $language->method('translateString')->willReturn(['first', 'second']);
        Registry::set(Language::class, $language);

        $this->assertSame('first', (new OxidShopAdapter())->translateString('KEY'));
    }

    private function useShopNamed(?string $name): void
    {
        $field = new stdClass();
        $field->value = $name;
        $shop = new stdClass();
        $shop->oxshops__oxname = $field;
        $config = $this->createStub(Config::class);
        $config->method('getActiveShop')->willReturn($shop);
        Registry::set(Config::class, $config);
    }

    private function useCurrency(object|false $currency): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getActShopCurrencyObject')->willReturn($currency);
        Registry::set(Config::class, $config);
    }
}
