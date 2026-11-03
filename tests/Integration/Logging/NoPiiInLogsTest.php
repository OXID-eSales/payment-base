<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Logging;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\OxidShopMailSender;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\OrderConfirmationMailHandler;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractOrderMailStore;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use OxidEsales\PaymentBase\Tests\Integration\Support\DeferredOrderMailFixture;
use OxidEsales\PaymentBase\Webhook\AbstractWebhookProcessor;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * VR-18 L6 - logs carry ids, never content. A checkout with fixture personal data runs through the
 * real wiring (order, deferred mail, commit, confirmation mail), then once more through the
 * failure paths whose exception messages embed that data (a DBAL error with bound parameters, a
 * handler error with the request body). Everything either wrote to the shop log or to any file
 * under the shop's log directory must be free of it.
 */
#[Group('integration')]
#[Group('mailpit')]
final class NoPiiInLogsTest extends IntegrationTestCase
{
    use CheckoutFixture;
    use DeferredOrderMailFixture;

    private const FIXTURE_STREET = 'Bill Street';
    private const TOKEN = 'tkn_9f3a1c';
    private const CONTRACT_TOKEN_QUERY = 'contract_token=abcdef0123456789';
    private const CLIENT_SECRET = 'client_secret=pi_3Nx_secret_Zk';

    /** @var array<string, int> log file => size before the test */
    private array $logSizesBefore = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpDeferredOrderMails();
        $this->logSizesBefore = $this->logSizes();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->tearDownDeferredOrderMails();
    }

    public function testTheHappyCheckoutLogsNoCustomerData(): void
    {
        $user = $this->createFixtureUser('nopii_ok');
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();
        [$orderId, $contractId] = $this->createOrderOnContract($user);

        $this->commit($contractId, $orderId);

        $this->assertLogsFreeOf([$this->addressOf($user)]);
    }

    public function testAFailingMailStoreAndAFailingWebhookLogNoCustomerDataAndNoToken(): void
    {
        $user = $this->createFixtureUser('nopii_fail');
        $leakyMessage = $this->leakyMessage($this->addressOf($user));
        /** @var ThrowableSummaryInterface $summary */
        $summary = $this->container()->get(ThrowableSummaryInterface::class);
        $logger = Registry::getLogger();

        $this->handlerOver($this->connectionFailingWith($leakyMessage), $summary, $logger)
            ->handle(new ContractCommittedEvent($this->contract('nopii-1'), new EventContext(), 'order-1'));
        $this->failingWebhook($leakyMessage, $summary, $logger)->process($this->webhookRequest());

        $this->assertLogsFreeOf([$this->addressOf($user)]);
        self::assertStringContainsString('42S22', $this->newLogLines(), 'the failure itself is still logged, by its SQLSTATE');
    }

    /**
     * @param list<string> $fixtureEmails
     */
    private function assertLogsFreeOf(array $fixtureEmails): void
    {
        $logged = $this->newLogLines();
        $forbidden = array_merge(
            $fixtureEmails,
            [self::FIXTURE_STREET, 'tkn_', 'contract_token=', 'client_secret']
        );
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $logged, 'the logs must not carry "' . $needle . '"');
        }
    }

    private function leakyMessage(string $email): string
    {
        return "An exception occurred while executing 'UPDATE oe_payments_contract SET OXORDERMAILS = ?' with params "
            . '["<p>Dear Bill-First, ' . $email . ', ' . self::FIXTURE_STREET . ' 1</p>"]'
            . ' Request body: {"cardToken":"' . self::TOKEN . '","redirectUrl":"https://shop.test/return?'
            . self::CONTRACT_TOKEN_QUERY . '","' . self::CLIENT_SECRET . '"}';
    }

    private function connectionFailingWith(string $message): Connection
    {
        $failure = new class ($message) extends DbalException {
            public function getSQLState(): string
            {
                return '42S22';
            }
        };
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException($failure);
        $connection->method('fetchAssociative')->willThrowException($failure);

        return $connection;
    }

    private function handlerOver(Connection $connection, ThrowableSummaryInterface $summary, LoggerInterface $logger): OrderConfirmationMailHandler
    {
        return new OrderConfirmationMailHandler(
            new DoctrineContractOrderMailStore($connection, $summary),
            new OxidShopMailSender(),
            $summary,
            $logger
        );
    }

    private function failingWebhook(string $message, ThrowableSummaryInterface $summary, LoggerInterface $logger): AbstractWebhookProcessor
    {
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);
        $repository->method('claimEvent')->willReturn(true);

        return new class ($repository, $logger, $summary, $message) extends AbstractWebhookProcessor {
            public function __construct(
                WebhookLogRepositoryInterface $repository,
                LoggerInterface $logger,
                ThrowableSummaryInterface $summary,
                private readonly string $message
            ) {
                parent::__construct($repository, $logger, $summary);
            }

            protected function getProviderName(): string
            {
                return 'fixture';
            }

            protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
            {
                return new WebhookEvent('evt_nopii', 'payment.paid', [], time());
            }

            protected function processEvent(WebhookEvent $event): WebhookResult
            {
                throw new RuntimeException($this->message);
            }

            protected function getContractIdFromResult(WebhookResult $result): ?string
            {
                return null;
            }
        };
    }

    private function webhookRequest(): WebhookRequest
    {
        return new WebhookRequest('{"id":"evt_nopii"}', 'sig', '127.0.0.1', new DateTimeImmutable());
    }

    private function commit(string $contractId, string $orderId): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $this->container()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new ContractCommittedEvent($this->contract($contractId), new EventContext(), $orderId));
    }

    private function contract(string $contractId): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);

        return new PaymentContract(1, 'nopii-user', $snapshot, $contractId);
    }

    private function container(): ContainerInterface
    {
        return ContainerFactory::getInstance()->getContainer();
    }

    /**
     * @return array<string, int>
     */
    private function logSizes(): array
    {
        $sizes = [];
        foreach ($this->logFiles() as $file) {
            $sizes[$file] = (int) filesize($file);
        }

        return $sizes;
    }

    /**
     * The shop log and every provider's own file log live under the same directory.
     *
     * @return list<string>
     */
    private function logFiles(): array
    {
        /** @var ContextInterface $context */
        $context = $this->container()->get(ContextInterface::class);
        $logDirectory = dirname($context->getLogFilePath());
        $files = glob($logDirectory . '/*.log') ?: [];
        $nested = glob($logDirectory . '/*/*.log') ?: [];

        return array_values(array_merge($files, $nested));
    }

    private function newLogLines(): string
    {
        $written = '';
        foreach ($this->logFiles() as $file) {
            $written .= (string) file_get_contents($file, false, null, $this->logSizesBefore[$file] ?? 0);
        }

        return $written;
    }
}
