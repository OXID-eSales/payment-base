<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL;

use GraphQL\Error\FormattedError;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;
use OxidEsales\PaymentBase\GraphQL\Controller\PaymentClientConfig;
use PHPUnit\Framework\Attributes\Group;

/**
 * The refusals of `paymentClientConfig` as the real webonyx formatter hands them to a headless client, from the
 * controller of the live container. The domain exceptions behind them are plain (GraphQL is optional); what the
 * client sees must stay what it was when they were graphql-base errors: the message and the category
 * `requesterror`, with no `errorCode`.
 *
 * Needs graphql-base; skipped where it is not installed.
 */
#[Group('integration')]
final class PaymentClientConfigRefusalTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Error::class)) {
            self::markTestSkipped('graphql-base is not installed');
        }
    }

    public function testAnUnknownProviderReachesTheClientAsAClientSafeRequestError(): void
    {
        $error = $this->refusal('no-such-provider', 'x');

        self::assertSame('Unknown payment provider "no-such-provider"', $this->formatted($error)['message']);
        $this->assertClientSafeRequestErrorWithoutErrorCode($error);
    }

    public function testAMalformedClientKeyReachesTheClientAsAClientSafeRequestError(): void
    {
        $error = $this->refusal('stripe', 'AAAA');
        if (str_starts_with($error->getMessage(), 'Unknown payment provider')) {
            self::markTestSkipped('the stripe module is not active in this shop');
        }

        self::assertSame('Invalid clientPublicKey: expected an uncompressed point of 65 bytes', $this->formatted($error)['message']);
        $this->assertClientSafeRequestErrorWithoutErrorCode($error);
    }

    private function assertClientSafeRequestErrorWithoutErrorCode(Error $error): void
    {
        self::assertTrue($error->isClientSafe());
        self::assertSame(ErrorCategories::REQUESTERROR, $error->getCategory());
        self::assertArrayNotHasKey('extensions', $this->formatted($error));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatted(Error $error): array
    {
        return FormattedError::createFromException($error);
    }

    private function refusal(string $provider, string $clientPublicKey): Error
    {
        /** @var PaymentClientConfig $controller */
        $controller = ContainerFactory::getInstance()->getContainer()->get(PaymentClientConfig::class);
        try {
            $controller->paymentClientConfig($provider, $clientPublicKey);
        } catch (Error $error) {
            return $error;
        }
        self::fail('the query was not refused');
    }
}
