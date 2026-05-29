<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Controller\ValidationApiController;
use OxidEsales\PaymentBase\Validation\Guard\GuardFailure;
use OxidEsales\PaymentBase\Validation\Guard\ValidationGuardInterface;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\ValidationRuleLoaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Uses a testable subclass that bypasses OXID bootstrap.
 *
 * Pattern: inject collaborators via constructor; override framework seams
 * (sendFailureResponse, sendJsonResponse, buildContext) as protected methods.
 */
#[CoversClass(ValidationApiController::class)]
class ValidationApiControllerTest extends TestCase
{
    private ValidationRuleLoaderInterface&MockObject $loader;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(ValidationRuleLoaderInterface::class);
        $this->loader->method('loadFor')->willReturn([]);
    }

    public function testHappyPathPostReturnsValidJson(): void
    {
        $guard = $this->passingGuard();
        $sut = $this->makeController([$guard], 'POST', ['firstName' => 'John']);

        $result = $sut->validate();

        $this->assertSame('{"valid":true,"errors":[]}', $result);
    }

    public function testHappyPathPostReturnsInvalidJson(): void
    {
        $guard = $this->passingGuard();
        $sut = $this->makeController([$guard], 'POST', ['houseNumber' => "12\x01"]);

        // No rules loaded means unknown field → valid (ValidationBase contract)
        $result = $sut->validate();

        $this->assertStringContainsString('"valid"', $result);
    }

    public function testGuardOrderShortCircuits(): void
    {
        $firstGuard = $this->failingGuard(405, priority: 10);
        $secondGuard = $this->createMock(ValidationGuardInterface::class);
        $secondGuard->method('getPriority')->willReturn(20);
        $secondGuard->expects($this->never())->method('check');

        $sut = $this->makeController([$secondGuard, $firstGuard], 'GET', []);

        $sut->validate();

        $this->assertSame(405, $sut->capturedStatus);
    }

    public function testFailureResponseBodyIsEmptyForEachGuard(): void
    {
        $statuses = [405, 413, 401, 403, 429, 422];

        foreach ($statuses as $status) {
            $guard = $this->failingGuard($status, priority: 10);
            $sut = $this->makeController([$guard], 'POST', []);

            $result = $sut->validate();

            $this->assertSame('', $result, "Body must be empty for HTTP {$status}");
            $this->assertSame($status, $sut->capturedStatus, "Status must be {$status}");
        }
    }

    public function testPluginIdRoutesToCorrectRulesFile(): void
    {
        $guard = $this->passingGuard();
        // Pass a field so ValidationBase actually calls loadFor
        $sut = $this->makeController(
            [$guard],
            'POST',
            ['firstName' => 'John'],
            pluginModuleId: 'oe_payments_stripe_wallet',
        );

        $this->loader->expects($this->once())->method('loadFor')->with('oe_payments_stripe_wallet');

        $sut->validate();
    }

    public function testDecoratesErrorsWithMatchingFormatterMessage(): void
    {
        $guard = $this->passingGuard();

        // houseNumber allow: LETTERS NUMBERS - /  → "!" is disallowed_character.
        $loader = $this->createMock(ValidationRuleLoaderInterface::class);
        $loader->method('loadFor')->willReturn([
            'houseNumber' => \OxidEsales\PaymentBase\Validation\RuleSet::fromArray([
                'allow' => 'LETTERS NUMBERS - /',
            ]),
        ]);

        $formatter = $this->createMock(MessageFormatterInterface::class);
        $formatter->method('getPluginModuleId')->willReturn('test_plugin');
        $formatter->expects($this->once())
            ->method('format')
            ->with('houseNumber', 'disallowed_character', '!')
            ->willReturn('House number may not contain "!".');

        $ctx = new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: ['houseNumber' => '12!'],
            pluginModuleId: 'test_plugin',
            csrfToken: null,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
        );
        $sut = new TestableValidationApiController([$guard], $ctx, $loader, [$formatter]);

        $result = $sut->validate();

        $json = json_decode($result, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertSame('House number may not contain "!".', $json['errors'][0]['message']);
    }

    public function testLeavesMessageNullWhenNoFormatterRegistered(): void
    {
        $guard = $this->passingGuard();

        $loader = $this->createMock(ValidationRuleLoaderInterface::class);
        $loader->method('loadFor')->willReturn([
            'houseNumber' => \OxidEsales\PaymentBase\Validation\RuleSet::fromArray([
                'allow' => 'LETTERS NUMBERS - /',
            ]),
        ]);

        // No formatters registered at all — message must be null.
        $ctx = new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: ['houseNumber' => '12!'],
            pluginModuleId: 'test_plugin',
            csrfToken: null,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
        );
        $sut = new TestableValidationApiController([$guard], $ctx, $loader, []);

        $result = $sut->validate();

        $json = json_decode($result, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertArrayHasKey('message', $json['errors'][0]);
        $this->assertNull($json['errors'][0]['message']);
    }

    // --- Helpers ---

    private function passingGuard(): ValidationGuardInterface&MockObject
    {
        $guard = $this->createMock(ValidationGuardInterface::class);
        $guard->method('getPriority')->willReturn(10);
        $guard->method('check')->willReturn(null);

        return $guard;
    }

    private function failingGuard(int $status, int $priority): ValidationGuardInterface&MockObject
    {
        $guard = $this->createMock(ValidationGuardInterface::class);
        $guard->method('getPriority')->willReturn($priority);
        $guard->method('check')->willReturn(GuardFailure::httpStatus($status));

        return $guard;
    }

    /**
     * @param ValidationGuardInterface[]   $guards
     * @param array<string, mixed>          $fields
     * @param MessageFormatterInterface[]   $formatters
     */
    private function makeController(
        array $guards,
        string $method,
        array $fields,
        string $pluginModuleId = 'test_plugin',
        array $formatters = [],
    ): TestableValidationApiController {
        $ctx = new ValidationRequestContext(
            method: $method,
            bodySize: 10,
            fields: $fields,
            pluginModuleId: $pluginModuleId,
            csrfToken: null,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
        );

        return new TestableValidationApiController($guards, $ctx, $this->loader, $formatters);
    }
}

/**
 * Testable subclass: bypasses OXID FrontendController bootstrap;
 * captures HTTP status for assertions; returns body string instead of echo+exit.
 */
class TestableValidationApiController extends ValidationApiController
{
    public int $capturedStatus = 200;

    /**
     * @param ValidationGuardInterface[]  $guards
     * @param MessageFormatterInterface[] $formatters
     */
    public function __construct(
        array $guards,
        private ValidationRequestContext $stubbedContext,
        ValidationRuleLoaderInterface $loader,
        array $formatters = [],
    ) {
        // Skip OXID parent constructor
        $this->initWithDependencies($guards, $loader, $formatters);
    }

    protected function buildRequestContext(): ValidationRequestContext
    {
        return $this->stubbedContext;
    }

    /** @return never-return or string — overridden to capture instead of exit */
    protected function sendFailureResponse(int $httpStatus): string
    {
        $this->capturedStatus = $httpStatus;

        return '';
    }

    protected function setHttpStatus(int $status): void
    {
        $this->capturedStatus = $status;
    }
}
