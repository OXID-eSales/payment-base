<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Exception;

use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\GraphQL\Exception\GuestDetailsRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The refusal of invalid guest details, as the GraphQL client sees it: it has
 * to be a graphql-base Error (client-safe, with `extensions.errorCode`), or
 * GraphQLite would report "Internal server error" for it.
 */
#[CoversClass(GuestDetailsRefused::class)]
final class GuestDetailsRefusedTest extends TestCase
{
    public function testConstruct_ARefusal_IsAClientSafeRequestError(): void
    {
        $error = new GuestDetailsRefused(HeadlessCheckoutException::guestDetailsRequired('street'));

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(ErrorCategories::REQUESTERROR, $error->getCategory());
    }

    public function testConstruct_ARefusal_CarriesTheStableErrorCodeAndTheFieldName(): void
    {
        $error = new GuestDetailsRefused(HeadlessCheckoutException::guestDetailsRequired('street'));

        self::assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $error->getExtensions()['errorCode']);
        self::assertSame('guest_details_required', $error->getExtensions()['errorCode']);
        self::assertStringContainsString('street', $error->getMessage());
    }

    public function testConstruct_ARefusal_KeepsTheOriginalAsPrevious(): void
    {
        $refusal = HeadlessCheckoutException::guestDetailsRequired('email');

        self::assertSame($refusal, (new GuestDetailsRefused($refusal))->getPrevious());
    }
}
