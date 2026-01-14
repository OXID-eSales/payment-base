<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setting\Setting as ModuleSetting;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidSolutionCatalysts\Payments\Stripe\Module;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ModuleConfigurationService
 * Tests configuration retrieval and mode switching logic
 */
class ModuleConfigurationServiceTest extends TestCase
{
    private ModuleConfigurationService $service;
    private ContextInterface&MockObject $context;
    private ModuleConfigurationDaoInterface&MockObject $moduleConfigDao;
    private ModuleConfiguration&MockObject $moduleConfig;
    private ShopAdapterInterface&MockObject $shopAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock ContextInterface
        $this->context = $this->createMock(ContextInterface::class);
        $this->context->method('getCurrentShopId')->willReturn(1);

        // Mock ModuleConfiguration
        $this->moduleConfig = $this->createMock(ModuleConfiguration::class);

        // Mock ModuleConfigurationDaoInterface
        $this->moduleConfigDao = $this->createMock(ModuleConfigurationDaoInterface::class);
        $this->moduleConfigDao
            ->method('get')
            ->with(Module::MODULE_ID, 1)
            ->willReturn($this->moduleConfig);

        // Mock ShopAdapterInterface (LSP - use interface, not concrete class)
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopUrl')->willReturn('https://test-shop.local/');

        // Create service under test (with ShopAdapter injected via DI)
        $this->service = new ModuleConfigurationService(
            $this->context,
            $this->moduleConfigDao,
            $this->shopAdapter
        );
    }

    /**
     * Helper to create a ModuleSetting mock that returns a value
     */
    private function createSettingMock(mixed $value): ModuleSetting&MockObject
    {
        $setting = $this->createMock(ModuleSetting::class);
        $setting->method('getValue')->willReturn($value);
        return $setting;
    }

    /**
     * Configure moduleConfig to return settings via callback
     */
    private function configureSettings(array $settings): void
    {
        $this->moduleConfig
            ->method('getModuleSetting')
            ->willReturnCallback(function (string $name) use ($settings): ModuleSetting {
                $value = $settings[$name] ?? '';
                return $this->createSettingMock($value);
            });
    }

    /**
     * Test 1: Get Stripe secret key in test mode
     */
    public function testGetsTestSecretKey(): void
    {
        // Given: Test mode enabled, test key configured
        $testSecretKey = 'sk_test_51ABC123';

        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => $testSecretKey,
        ]);

        // When: getSecretKey() called
        $result = $this->service->getSecretKey();

        // Then: Returns test secret key
        $this->assertEquals($testSecretKey, $result);
    }

    /**
     * Test 2: Get Stripe secret key in live mode
     */
    public function testGetsLiveSecretKey(): void
    {
        // Given: Live mode enabled, live key configured
        $liveSecretKey = 'sk_live_51XYZ789';

        $this->configureSettings([
            'sStripeMode' => 'live',
            'sStripeLiveToken' => $liveSecretKey,
        ]);

        // When: getSecretKey() called
        $result = $this->service->getSecretKey();

        // Then: Returns live secret key
        $this->assertEquals($liveSecretKey, $result);
    }

    /**
     * Test 3: Get webhook secret
     */
    public function testGetsWebhookSecret(): void
    {
        // Given: Webhook secret configured
        $webhookSecret = 'whsec_ABC123';

        $this->configureSettings([
            'sStripeWebhookEndpointSecret' => $webhookSecret,
        ]);

        // When: getWebhookSecret() called
        $result = $this->service->getWebhookSecret();

        // Then: Returns webhook secret
        $this->assertEquals($webhookSecret, $result);
    }

    /**
     * Test 4: Get webhook endpoint
     */
    public function testGetsWebhookEndpoint(): void
    {
        // Given: Webhook endpoint configured
        $webhookEndpoint = 'https://example.com/webhook';

        $this->configureSettings([
            'sStripeWebhookEndpoint' => $webhookEndpoint,
        ]);

        // When: getWebhookEndpoint() called
        $result = $this->service->getWebhookEndpoint();

        // Then: Returns webhook endpoint
        $this->assertEquals($webhookEndpoint, $result);
    }

    /**
     * Test 5: Is test mode enabled
     */
    public function testIsTestModeEnabled(): void
    {
        // Given: Mode setting = 'test'
        $this->configureSettings([
            'sStripeMode' => 'test',
        ]);

        // When: isTestMode() called
        $result = $this->service->isTestMode();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 6: Is test mode disabled
     */
    public function testIsTestModeDisabled(): void
    {
        // Given: Mode setting = 'live'
        $this->configureSettings([
            'sStripeMode' => 'live',
        ]);

        // When: isTestMode() called
        $result = $this->service->isTestMode();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 7: Check if transaction logging is enabled
     */
    public function testIsTransactionLoggingEnabled(): void
    {
        // Given: Transaction logging enabled
        $this->configureSettings([
            'blStripeLogTransactionInfo' => true,
        ]);

        // When: isTransactionLoggingEnabled() called
        $result = $this->service->isTransactionLoggingEnabled();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 8: Get status mapping for pending orders
     */
    public function testGetsStatusPending(): void
    {
        // Given: Status pending = 'NOT_FINISHED'
        $this->configureSettings([
            'sStripeStatusPending' => 'NOT_FINISHED',
        ]);

        // When: getStatusPending() called
        $result = $this->service->getStatusPending();

        // Then: Returns 'NOT_FINISHED'
        $this->assertEquals('NOT_FINISHED', $result);
    }

    /**
     * Test 9: Get status mapping for processing orders
     */
    public function testGetsStatusProcessing(): void
    {
        // Given: Status processing = 'OK'
        $this->configureSettings([
            'sStripeStatusProcessing' => 'OK',
        ]);

        // When: getStatusProcessing() called
        $result = $this->service->getStatusProcessing();

        // Then: Returns 'OK'
        $this->assertEquals('OK', $result);
    }

    /**
     * Test 10: Get publishable key in test mode
     */
    public function testGetsTestPublishableKey(): void
    {
        // Given: Test mode enabled, test publishable key configured
        $testPublishableKey = 'pk_test_ABC123';

        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => $testPublishableKey,
        ]);

        // When: getPublishableKey() called
        $result = $this->service->getPublishableKey();

        // Then: Returns test publishable key
        $this->assertEquals($testPublishableKey, $result);
    }

    /**
     * Test 11: Get publishable key in live mode
     */
    public function testGetsLivePublishableKey(): void
    {
        // Given: Live mode enabled, live publishable key configured
        $livePublishableKey = 'pk_live_XYZ789';

        $this->configureSettings([
            'sStripeMode' => 'live',
            'sStripeLivePk' => $livePublishableKey,
        ]);

        // When: getPublishableKey() called
        $result = $this->service->getPublishableKey();

        // Then: Returns live publishable key
        $this->assertEquals($livePublishableKey, $result);
    }

    /**
     * Test 12: Is configured returns true when keys are set
     */
    public function testIsConfiguredReturnsTrueWhenKeysSet(): void
    {
        // Given: Token configured (isConfigured only checks token now)
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => 'sk_test_ABC123',
        ]);

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 13: Is configured returns false when token is missing
     */
    public function testIsConfiguredReturnsFalseWhenSecretKeyMissing(): void
    {
        // Given: Token not configured
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => '',
        ]);

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 14: Get token in test mode
     */
    public function testGetsTestToken(): void
    {
        // Given: Test mode enabled, test token configured
        $testToken = 'token_test_ABC123';

        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => $testToken,
        ]);

        // When: getToken() called
        $result = $this->service->getToken();

        // Then: Returns test token
        $this->assertEquals($testToken, $result);
    }

    /**
     * Test 15: Check if payment method should be removed by billing country
     */
    public function testIsRemoveByBillingCountry(): void
    {
        // Given: Setting enabled
        $this->configureSettings([
            'blStripeRemoveByBillingCountry' => true,
        ]);

        // When: isRemoveByBillingCountry() called
        $result = $this->service->isRemoveByBillingCountry();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 16: Check if payment method should be removed by basket currency
     */
    public function testIsRemoveByBasketCurrency(): void
    {
        // Given: Setting disabled
        $this->configureSettings([
            'blStripeRemoveByBasketCurrency' => false,
        ]);

        // When: isRemoveByBasketCurrency() called
        $result = $this->service->isRemoveByBasketCurrency();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 17: Check if customer email should be provided to Stripe
     */
    public function testShouldProvideCustomerEmail(): void
    {
        // Given: Setting enabled
        $this->configureSettings([
            'blStripeProvideCustomerEmailAddress' => true,
        ]);

        // When: shouldProvideCustomerEmail() called
        $result = $this->service->shouldProvideCustomerEmail();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 18: Check if cron finish orders is active
     */
    public function testIsCronFinishOrdersActive(): void
    {
        // Given: Cron job active
        $this->configureSettings([
            'sStripeCronFinishOrdersActive' => true,
        ]);

        // When: isCronFinishOrdersActive() called
        $result = $this->service->isCronFinishOrdersActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 19: Check if cron second chance is active
     */
    public function testIsCronSecondChanceActive(): void
    {
        // Given: Cron job active
        $this->configureSettings([
            'sStripeCronSecondChanceActive' => true,
        ]);

        // When: isCronSecondChanceActive() called
        $result = $this->service->isCronSecondChanceActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 20: Get cron second chance time diff
     */
    public function testGetsCronSecondChanceTimeDiff(): void
    {
        // Given: Time diff = 3 days
        $this->configureSettings([
            'iStripeCronSecondChanceTimeDiff' => '3',
        ]);

        // When: getCronSecondChanceTimeDiff() called
        $result = $this->service->getCronSecondChanceTimeDiff();

        // Then: Returns 3
        $this->assertEquals(3, $result);
    }

    /**
     * Test 21: Get cron second chance time diff with default
     */
    public function testGetsCronSecondChanceTimeDiffDefault(): void
    {
        // Given: No value configured
        $this->configureSettings([
            'iStripeCronSecondChanceTimeDiff' => null,
        ]);

        // When: getCronSecondChanceTimeDiff() called
        $result = $this->service->getCronSecondChanceTimeDiff();

        // Then: Returns default value of 1
        $this->assertEquals(1, $result);
    }

    /**
     * Test 22: Check if cron order shipment is active
     */
    public function testIsCronOrderShipmentActive(): void
    {
        // Given: Cron job active
        $this->configureSettings([
            'sStripeCronOrderShipmentActive' => true,
        ]);

        // When: isCronOrderShipmentActive() called
        $result = $this->service->isCronOrderShipmentActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 23: Get cron secure key
     */
    public function testGetsCronSecureKey(): void
    {
        // Given: Secure key configured
        $secureKey = 'my_secure_key_123';

        $this->configureSettings([
            'sStripeCronSecureKey' => $secureKey,
        ]);

        // When: getCronSecureKey() called
        $result = $this->service->getCronSecureKey();

        // Then: Returns secure key
        $this->assertEquals($secureKey, $result);
    }

    /**
     * Test 24: Get capture mode returns 'manual' when configured
     */
    public function testGetsCaptureModeManual(): void
    {
        // Given: Capture mode configured as manual
        $this->configureSettings([
            'sStripeCaptureMode' => 'manual',
        ]);

        // When: getCaptureMode() called
        $result = $this->service->getCaptureMode();

        // Then: Returns 'manual'
        $this->assertEquals('manual', $result);
    }

    /**
     * Test 25: Get capture mode returns default when empty
     */
    public function testGetsCaptureModeDefault(): void
    {
        // Given: No capture mode configured
        $this->configureSettings([
            'sStripeCaptureMode' => '',
        ]);

        // When: getCaptureMode() called
        $result = $this->service->getCaptureMode();

        // Then: Returns 'automatic' as default
        $this->assertEquals('automatic', $result);
    }

    /**
     * Test 25a: Get capture mode returns 'automatic' when configured
     */
    public function testGetsCaptureModeAutomatic(): void
    {
        // Given: Capture mode configured as automatic
        $this->configureSettings([
            'sStripeCaptureMode' => 'automatic',
        ]);

        // When: getCaptureMode() called
        $result = $this->service->getCaptureMode();

        // Then: Returns 'automatic'
        $this->assertEquals('automatic', $result);
    }

    /**
     * Test 25b: isAutomaticCapture returns true when automatic mode
     */
    public function testIsAutomaticCaptureReturnsTrue(): void
    {
        // Given: Capture mode configured as automatic
        $this->configureSettings([
            'sStripeCaptureMode' => 'automatic',
        ]);

        // When: isAutomaticCapture() called
        $result = $this->service->isAutomaticCapture();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 25c: isAutomaticCapture returns false when manual mode
     */
    public function testIsAutomaticCaptureReturnsFalse(): void
    {
        // Given: Capture mode configured as manual
        $this->configureSettings([
            'sStripeCaptureMode' => 'manual',
        ]);

        // When: isAutomaticCapture() called
        $result = $this->service->isAutomaticCapture();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 25d: isManualCapture returns true when manual mode
     */
    public function testIsManualCaptureReturnsTrue(): void
    {
        // Given: Capture mode configured as manual
        $this->configureSettings([
            'sStripeCaptureMode' => 'manual',
        ]);

        // When: isManualCapture() called
        $result = $this->service->isManualCapture();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 25e: isManualCapture returns false when automatic mode
     */
    public function testIsManualCaptureReturnsFalse(): void
    {
        // Given: Capture mode configured as automatic
        $this->configureSettings([
            'sStripeCaptureMode' => 'automatic',
        ]);

        // When: isManualCapture() called
        $result = $this->service->isManualCapture();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 25f: getStripeCaptureMethod returns correct value for Stripe API
     */
    public function testGetStripeCaptureMethodReturnsManual(): void
    {
        // Given: Capture mode configured as manual
        $this->configureSettings([
            'sStripeCaptureMode' => 'manual',
        ]);

        // When: getStripeCaptureMethod() called
        $result = $this->service->getStripeCaptureMethod();

        // Then: Returns 'manual' as expected by Stripe API
        $this->assertEquals('manual', $result);
    }

    /**
     * Test 25g: getStripeCaptureMethod returns automatic by default
     */
    public function testGetStripeCaptureMethodReturnsAutomatic(): void
    {
        // Given: Capture mode configured as automatic
        $this->configureSettings([
            'sStripeCaptureMode' => 'automatic',
        ]);

        // When: getStripeCaptureMethod() called
        $result = $this->service->getStripeCaptureMethod();

        // Then: Returns 'automatic' as expected by Stripe API
        $this->assertEquals('automatic', $result);
    }

    /**
     * Test 25h: getCaptureMode defaults to automatic for invalid value
     */
    public function testGetsCaptureModeDefaultsForInvalidValue(): void
    {
        // Given: Invalid capture mode value
        $this->configureSettings([
            'sStripeCaptureMode' => 'invalid_value',
        ]);

        // When: getCaptureMode() called
        $result = $this->service->getCaptureMode();

        // Then: Returns 'automatic' as default
        $this->assertEquals('automatic', $result);
    }

    /**
     * Test 26: Get method returns empty string on exception
     */
    public function testGetReturnsEmptyStringOnException(): void
    {
        // Given: Module setting throws exception
        $this->moduleConfig
            ->method('getModuleSetting')
            ->willThrowException(new \Exception('Setting not found'));

        // When: any config method is called
        $result = $this->service->getSecretKey();

        // Then: Returns empty string (not throwing)
        $this->assertEquals('', $result);
    }

    /**
     * Test 27: Validate key pair returns true when keys are from same account
     */
    public function testValidateKeyPairReturnsTrueForMatchingKeys(): void
    {
        // Given: Keys from the same Stripe account (same first 10 chars after prefix)
        // Both keys have "51ABC12345" as the first 10 chars of the account portion
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => 'pk_test_51ABC12345DEF456GHI789',
            'sStripeTestToken' => 'sk_test_51ABC12345XYZ000111222',
        ]);

        // When: validateKeyPair() called
        $result = $this->service->validateKeyPair();

        // Then: Returns true (account IDs match)
        $this->assertTrue($result);
    }

    /**
     * Test 28: Validate key pair returns false when keys are from different accounts
     */
    public function testValidateKeyPairReturnsFalseForMismatchedKeys(): void
    {
        // Given: Keys from different Stripe accounts
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => 'pk_test_51ABC123DEF456GHI789',
            'sStripeTestToken' => 'sk_test_51XYZ789DEF456GHI789', // Different account ID
        ]);

        // When: validateKeyPair() called
        $result = $this->service->validateKeyPair();

        // Then: Returns false (account IDs don't match)
        $this->assertFalse($result);
    }

    /**
     * Test 29: Validate key pair returns false when publishable key is empty
     */
    public function testValidateKeyPairReturnsFalseWhenPublishableKeyEmpty(): void
    {
        // Given: Empty publishable key
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => '',
            'sStripeTestToken' => 'sk_test_51ABC123DEF456GHI789',
        ]);

        // When: validateKeyPair() called
        $result = $this->service->validateKeyPair();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 30: Validate key pair returns false when secret key is empty
     */
    public function testValidateKeyPairReturnsFalseWhenSecretKeyEmpty(): void
    {
        // Given: Empty secret key
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => 'pk_test_51ABC123DEF456GHI789',
            'sStripeTestToken' => '',
        ]);

        // When: validateKeyPair() called
        $result = $this->service->validateKeyPair();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 31: Validate key pair returns false for invalid key format
     */
    public function testValidateKeyPairReturnsFalseForInvalidKeyFormat(): void
    {
        // Given: Invalid key format (missing proper prefix)
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => 'invalid_key_format',
            'sStripeTestToken' => 'also_invalid',
        ]);

        // When: validateKeyPair() called
        $result = $this->service->validateKeyPair();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 32: Validate key pair works for live mode keys
     */
    public function testValidateKeyPairWorksForLiveModeKeys(): void
    {
        // Given: Live mode keys from same account (same first 10 chars after prefix)
        $this->configureSettings([
            'sStripeMode' => 'live',
            'sStripeLivePk' => 'pk_live_51ABC12345DEF456GHI789',
            'sStripeLiveToken' => 'sk_live_51ABC12345XYZ000111222',
        ]);

        // When: validateKeyPair() called
        $result = $this->service->validateKeyPair();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 33: Get key validation error message for mismatched keys
     */
    public function testGetKeyValidationErrorForMismatchedKeys(): void
    {
        // Given: Keys from different accounts
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => 'pk_test_51ABC123DEF456GHI789',
            'sStripeTestToken' => 'sk_test_51XYZ789DEF456GHI789',
        ]);

        // When: getKeyValidationError() called
        $result = $this->service->getKeyValidationError();

        // Then: Returns error message about mismatched keys
        $this->assertStringContainsString('different Stripe accounts', $result);
    }

    /**
     * Test 34: Get key validation error returns null for valid keys
     */
    public function testGetKeyValidationErrorReturnsNullForValidKeys(): void
    {
        // Given: Valid matching keys (same first 10 chars after prefix)
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => 'pk_test_51ABC12345DEF456GHI789',
            'sStripeTestToken' => 'sk_test_51ABC12345XYZ000111222',
        ]);

        // When: getKeyValidationError() called
        $result = $this->service->getKeyValidationError();

        // Then: Returns null (no error)
        $this->assertNull($result);
    }

    // =========================================================================
    // Sprint 13: Webhook URL Tests (TDD RED → GREEN)
    // =========================================================================

    /**
     * Test 35: getWebhookUrl returns non-empty string
     *
     * @group webhook-url
     * @group sprint-13
     */
    public function testGetWebhookUrlReturnsNonEmptyString(): void
    {
        // Given: Service is configured (no special settings needed)
        $this->configureSettings([]);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: Returns non-empty URL
        $this->assertNotEmpty($result, 'Webhook URL should not be empty');
        $this->assertIsString($result);
    }

    /**
     * Test 36: getWebhookUrl contains webhook controller parameter
     *
     * @group webhook-url
     * @group sprint-13
     */
    public function testGetWebhookUrlContainsWebhookController(): void
    {
        // Given: Service is configured
        $this->configureSettings([]);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: URL contains controller parameter for webhook
        $this->assertStringContainsString('cl=', $result);
        $this->assertStringContainsString('webhook', $result);
    }

    /**
     * Test 37: getWebhookUrl starts with http scheme
     *
     * @group webhook-url
     * @group sprint-13
     */
    public function testGetWebhookUrlStartsWithHttpScheme(): void
    {
        // Given: Service is configured
        $this->configureSettings([]);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: URL starts with http:// or https://
        $startsWithHttp = str_starts_with($result, 'http://') || str_starts_with($result, 'https://');
        $this->assertTrue($startsWithHttp, "URL '{$result}' must start with http:// or https://");
    }

    /**
     * Test 38: getWebhookUrl does not have double slashes (except in scheme)
     *
     * @group webhook-url
     * @group sprint-13
     */
    public function testGetWebhookUrlNoDoubleSlashes(): void
    {
        // Given: Service is configured
        $this->configureSettings([]);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: No double slashes except in http://
        $urlWithoutScheme = preg_replace('#^https?://#', '', $result);
        $this->assertStringNotContainsString('//', $urlWithoutScheme);
    }

    /**
     * Test 39: getWebhookUrl uses stripe_webhook controller
     *
     * @group webhook-url
     * @group sprint-13
     */
    public function testGetWebhookUrlUsesStripeWebhookController(): void
    {
        // Given: Service is configured
        $this->configureSettings([]);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: URL uses osc_stripe_webhook controller (as registered in metadata.php)
        $this->assertStringContainsString('cl=osc_stripe_webhook', $result);
    }
}
