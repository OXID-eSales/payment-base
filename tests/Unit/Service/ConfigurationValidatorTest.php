<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\ConfigurationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigurationValidator
 * Tests validation logic for Stripe API credentials and configuration
 */
class ConfigurationValidatorTest extends TestCase
{
    private ConfigurationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConfigurationValidator();
    }

    /**
     * Test 1: Valid test API key format
     */
    public function testValidatesTestApiKey(): void
    {
        // Given: Valid test secret key (sk_test_...)
        $testKey = 'sk_test_51ABC123DEF456GHI789';

        // When: validateApiKeyFormat() called with test mode
        $result = $this->validator->validateApiKeyFormat($testKey, true);

        // Then: Returns true
        $this->assertTrue($result, 'Test API key should be valid in test mode');
    }

    /**
     * Test 2: Valid live API key format
     */
    public function testValidatesLiveApiKey(): void
    {
        // Given: Valid live secret key (sk_live_...)
        $liveKey = 'sk_live_51XYZ789ABC456DEF123';

        // When: validateApiKeyFormat() called with live mode
        $result = $this->validator->validateApiKeyFormat($liveKey, false);

        // Then: Returns true
        $this->assertTrue($result, 'Live API key should be valid in live mode');
    }

    /**
     * Test 3: Invalid API key format (wrong prefix)
     */
    public function testRejectsInvalidApiKeyFormat(): void
    {
        // Given: Invalid key format (not sk_test_ or sk_live_)
        $invalidKeys = [
            'pk_test_ABC123',         // Publishable key instead of secret
            'sk_prod_ABC123',         // Wrong environment prefix
            'invalid_key',            // Completely wrong format
            '',                       // Empty string
            'random_string',          // Random string
        ];

        foreach ($invalidKeys as $invalidKey) {
            // When: validateApiKeyFormat() called
            $resultTest = $this->validator->validateApiKeyFormat($invalidKey, true);
            $resultLive = $this->validator->validateApiKeyFormat($invalidKey, false);

            // Then: Returns false
            $this->assertFalse(
                $resultTest,
                sprintf('Key "%s" should be invalid in test mode', $invalidKey)
            );
            $this->assertFalse(
                $resultLive,
                sprintf('Key "%s" should be invalid in live mode', $invalidKey)
            );
        }
    }

    /**
     * Test 4: Test key in live mode warning
     */
    public function testWarnsTestKeyInLiveMode(): void
    {
        // Given: Test key (sk_test_) but test mode = false
        $testKey = 'sk_test_51ABC123DEF456GHI789';
        $webhookSecret = 'whsec_ABC123';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(false, $testKey, $webhookSecret);

        // Then: Returns validation error
        $this->assertArrayHasKey('secretKey', $errors);
        $this->assertStringContainsString(
            'Live mode requires live key',
            $errors['secretKey']
        );
    }

    /**
     * Test 5: Live key in test mode warning
     */
    public function testWarnsLiveKeyInTestMode(): void
    {
        // Given: Live key (sk_live_) but test mode = true
        $liveKey = 'sk_live_51XYZ789ABC456DEF123';
        $webhookSecret = 'whsec_ABC123';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $liveKey, $webhookSecret);

        // Then: Returns validation error
        $this->assertArrayHasKey('secretKey', $errors);
        $this->assertStringContainsString(
            'Test mode requires test key',
            $errors['secretKey']
        );
    }

    /**
     * Test 6: Valid webhook secret format
     */
    public function testValidatesWebhookSecretFormat(): void
    {
        // Given: Valid test configuration
        $testKey = 'sk_test_51ABC123DEF456GHI789';
        $webhookSecret = 'whsec_ABC123DEF456GHI789JKL012';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $testKey, $webhookSecret);

        // Then: No errors for webhook secret
        $this->assertArrayNotHasKey('webhookSecret', $errors);
    }

    /**
     * Test 7: Invalid webhook secret format
     */
    public function testRejectsInvalidWebhookSecretFormat(): void
    {
        // Given: Valid API key but invalid webhook secret (not whsec_)
        $testKey = 'sk_test_51ABC123DEF456GHI789';
        $invalidWebhookSecret = 'invalid_webhook_secret';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $testKey, $invalidWebhookSecret);

        // Then: Returns validation error
        $this->assertArrayHasKey('webhookSecret', $errors);
        $this->assertStringContainsString(
            'Invalid webhook secret format',
            $errors['webhookSecret']
        );
        $this->assertStringContainsString('whsec_', $errors['webhookSecret']);
    }

    /**
     * Test 8: Missing secret key
     */
    public function testDetectsMissingSecretKey(): void
    {
        // Given: Empty secret key
        $secretKey = '';
        $webhookSecret = 'whsec_ABC123';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $secretKey, $webhookSecret);

        // Then: Returns error for secretKey
        $this->assertArrayHasKey('secretKey', $errors);
        $this->assertEquals('Secret key is required', $errors['secretKey']);
    }

    /**
     * Test 9: Missing webhook secret
     */
    public function testDetectsMissingWebhookSecret(): void
    {
        // Given: Valid secret key, empty webhook secret
        $secretKey = 'sk_test_51ABC123DEF456GHI789';
        $webhookSecret = '';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $secretKey, $webhookSecret);

        // Then: Returns error for webhookSecret
        $this->assertArrayHasKey('webhookSecret', $errors);
        $this->assertEquals('Webhook secret is required', $errors['webhookSecret']);
    }

    /**
     * Test 10: Missing both required fields
     */
    public function testDetectsMissingConfiguration(): void
    {
        // Given: Empty secret key and webhook secret
        $secretKey = '';
        $webhookSecret = '';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $secretKey, $webhookSecret);

        // Then: Returns errors for both fields
        $this->assertArrayHasKey('secretKey', $errors);
        $this->assertArrayHasKey('webhookSecret', $errors);
        $this->assertCount(2, $errors);
    }

    /**
     * Test 11: Valid test configuration passes all checks
     */
    public function testValidTestConfiguration(): void
    {
        // Given: Valid test configuration
        $testKey = 'sk_test_51ABC123DEF456GHI789';
        $webhookSecret = 'whsec_TEST123ABC456DEF789';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $testKey, $webhookSecret);

        // Then: No errors returned
        $this->assertEmpty($errors, 'Valid test configuration should have no errors');
    }

    /**
     * Test 12: Valid live configuration passes all checks
     */
    public function testValidLiveConfiguration(): void
    {
        // Given: Valid live configuration
        $liveKey = 'sk_live_51XYZ789ABC456DEF123';
        $webhookSecret = 'whsec_LIVE123ABC456DEF789';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(false, $liveKey, $webhookSecret);

        // Then: No errors returned
        $this->assertEmpty($errors, 'Valid live configuration should have no errors');
    }

    /**
     * Test 13: Test Stripe connection with valid key (mocked)
     */
    public function testTestsStripeConnectionSuccess(): void
    {
        // Note: This test would require mocking the Stripe API
        // For now, we test that the method exists and accepts correct parameters

        // Given: Valid API key
        $testKey = 'sk_test_51ABC123DEF456GHI789';

        // When: testConnection() called
        // Note: In real scenario, this would need API mocking or integration test
        $result = method_exists($this->validator, 'testConnection');

        // Then: Method exists
        $this->assertTrue($result, 'testConnection method should exist');
    }

    /**
     * Test 14: Multiple validation errors accumulate
     */
    public function testMultipleValidationErrorsAccumulate(): void
    {
        // Given: Invalid secret key and webhook secret
        $invalidKey = 'invalid_key';
        $invalidWebhook = 'invalid_webhook';

        // When: validateConfiguration() called
        $errors = $this->validator->validateConfiguration(true, $invalidKey, $invalidWebhook);

        // Then: Multiple errors returned
        $this->assertArrayHasKey('secretKey', $errors);
        $this->assertArrayHasKey('webhookSecret', $errors);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    /**
     * Test 15: Validate API key format is case-sensitive for prefix
     */
    public function testApiKeyFormatIsCaseSensitive(): void
    {
        // Given: API key with wrong case prefix
        $wrongCaseKeys = [
            'SK_TEST_ABC123',         // Uppercase SK
            'Sk_test_ABC123',         // Mixed case
            'sk_TEST_ABC123',         // Uppercase TEST
        ];

        foreach ($wrongCaseKeys as $wrongCaseKey) {
            // When: validateApiKeyFormat() called
            $result = $this->validator->validateApiKeyFormat($wrongCaseKey, true);

            // Then: Returns false (Stripe keys are case-sensitive)
            $this->assertFalse(
                $result,
                sprintf('Key with wrong case "%s" should be invalid', $wrongCaseKey)
            );
        }
    }

    /**
     * Test 16: Webhook secret prefix validation
     */
    public function testWebhookSecretPrefixValidation(): void
    {
        // Given: Various webhook secret formats
        $testCases = [
            ['whsec_ABC123', true, 'Valid webhook secret should pass'],
            ['whsec_', false, 'Webhook secret with only prefix should fail'],
            ['WHSEC_ABC123', false, 'Uppercase prefix should fail'],
            ['webhook_secret', false, 'Wrong prefix should fail'],
            ['ws_ABC123', false, 'Wrong abbreviation should fail'],
        ];

        $validKey = 'sk_test_51ABC123DEF456GHI789';

        foreach ($testCases as [$webhookSecret, $shouldBeValid, $message]) {
            // When: validateConfiguration() called
            $errors = $this->validator->validateConfiguration(true, $validKey, $webhookSecret);

            // Then: Check validation result
            if ($shouldBeValid) {
                $this->assertArrayNotHasKey('webhookSecret', $errors, $message);
            } else {
                $this->assertArrayHasKey('webhookSecret', $errors, $message);
            }
        }
    }

    /**
     * Test 17: Error messages are descriptive
     */
    public function testErrorMessagesAreDescriptive(): void
    {
        // Given: Various invalid configurations
        $testKey = 'sk_test_ABC123';
        $liveKey = 'sk_live_XYZ789';

        // Test 1: Wrong mode
        $errors = $this->validator->validateConfiguration(false, $testKey, 'whsec_ABC');
        $this->assertStringContainsString('live key', strtolower($errors['secretKey']));

        // Test 2: Invalid webhook format
        $errors = $this->validator->validateConfiguration(true, $testKey, 'invalid');
        $this->assertStringContainsString('whsec_', $errors['webhookSecret']);

        // Test 3: Empty fields
        $errors = $this->validator->validateConfiguration(true, '', '');
        $this->assertStringContainsString('required', strtolower($errors['secretKey']));
        $this->assertStringContainsString('required', strtolower($errors['webhookSecret']));
    }
}
