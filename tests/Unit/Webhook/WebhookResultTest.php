<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\PaymentBase\Webhook\WebhookResult::class)]
#[Group('sprint-13')]
#[Group('webhook')]
final class WebhookResultTest extends TestCase
{
    #[Test]
    public function successResultHasCorrectStatus(): void
    {
        $result = WebhookResult::success('handled');

        $this->assertTrue($result->success);
        $this->assertSame('handled', $result->action);
        $this->assertNull($result->error);
    }

    #[Test]
    public function failureResultContainsError(): void
    {
        $result = WebhookResult::failure('error', 'Something went wrong');

        $this->assertFalse($result->success);
        $this->assertSame('error', $result->action);
        $this->assertSame('Something went wrong', $result->error);
    }

    #[Test]
    public function canCreateWithConstructor(): void
    {
        $result = new WebhookResult(
            success: true,
            action: 'processed',
            error: null
        );

        $this->assertTrue($result->success);
        $this->assertSame('processed', $result->action);
    }

    #[Test]
    public function propertiesAreReadOnly(): void
    {
        $result = WebhookResult::success('test');

        $reflection = new \ReflectionClass($result);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }

    #[Test]
    public function skippedCreatesSuccessWithSkippedAction(): void
    {
        $result = WebhookResult::skipped('No handler found');

        $this->assertTrue($result->success);
        $this->assertSame('skipped', $result->action);
        $this->assertSame('No handler found', $result->error);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $result = WebhookResult::success('handled');

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertTrue($array['success']);
        $this->assertSame('handled', $array['action']);
        $this->assertNull($array['error']);
    }

    #[Test]
    public function toArrayIncludesErrorWhenPresent(): void
    {
        $result = WebhookResult::failure('error', 'Test error message');

        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertSame('error', $array['action']);
        $this->assertSame('Test error message', $array['error']);
    }

    #[Test]
    public function isSuccessReturnsTrueForSuccessResult(): void
    {
        $result = WebhookResult::success('handled');

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function isSuccessReturnsFalseForFailureResult(): void
    {
        $result = WebhookResult::failure('error', 'Failed');

        $this->assertFalse($result->isSuccess());
    }

    #[Test]
    public function isFailureReturnsTrueForFailureResult(): void
    {
        $result = WebhookResult::failure('error', 'Failed');

        $this->assertTrue($result->isFailure());
    }

    #[Test]
    public function isFailureReturnsFalseForSuccessResult(): void
    {
        $result = WebhookResult::success('handled');

        $this->assertFalse($result->isFailure());
    }
}
