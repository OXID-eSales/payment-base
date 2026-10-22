<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout\Headless;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\ContractFirstPaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestBasketClaimInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutService;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistry;
use OxidEsales\PaymentBase\Checkout\Headless\ReturnResolverRegistry;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlPolicyInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 140a against the real shop: an anonymous token with guest details
 * becomes a passwordless shop user, takes over the anonymous basket, and the
 * checkout runs on to a contract that is READY_TO_COMMIT. Only the provider
 * handler is replaced (no PSP in a test): it opens a contract for the basket
 * and user it is handed, as a real one does.
 */
#[Group('integration')]
final class GuestCheckoutStartTest extends IntegrationTestCase
{
    use CheckoutFixture;

    // CheckoutFixture's payment: active, assigned to the delivery set, so the basket calculates.
    private const PAYMENT_ID_OF_FIXTURE = 'e2e_sa_payment';
    private const ANONYMOUS_TOKEN_USER = 'anon_token_user_not_in_oxuser';

    public function testStart_AnonymousTokenWithGuestDetails_CreatesGuestUserAndReachesReadyToCommit(): void
    {
        $email = 'guest_' . uniqid() . '@example.invalid';
        $basketId = $this->anonymousBasket();
        $handler = new ContractOpeningTestHandler($this->contracts());

        $result = $this->service($handler)->start($this->request($basketId, $this->details($email)));

        $guestId = $this->userIdOf($email);
        self::assertNotNull($guestId, 'the guest account exists');
        self::assertSame('', (string) $this->column('oxuser', 'OXPASSWORD', $guestId), 'and cannot log in');
        self::assertSame($guestId, $this->column('oxuserbaskets', 'OXUSERID', $basketId), 'the basket became the guest\'s');
        self::assertSame('Ada', $this->column('oxuser', 'OXFNAME', $guestId));
        self::assertSame('Berlin', $this->column('oxuser', 'OXCITY', $guestId));

        $contract = $this->contracts()->findById($result->contractId);
        self::assertNotNull($contract);
        self::assertSame('ready_to_commit', $contract->getStateValue());
        self::assertSame($guestId, $contract->getUserId());
        self::assertSame($guestId, $handler->userIdSeen);
    }

    public function testStart_ExistingGuestEmail_ReusesTheGuestAccountAndUpdatesItsAddress(): void
    {
        $email = 'guest_' . uniqid() . '@example.invalid';
        $service = $this->service(new ContractOpeningTestHandler($this->contracts()));

        $service->start($this->request($this->anonymousBasket(), $this->details($email)));
        $firstId = $this->userIdOf($email);
        $service->start($this->request($this->anonymousBasket(), $this->details($email, city: 'Hamburg')));

        self::assertSame($firstId, $this->userIdOf($email), 'one account per e-mail');
        self::assertSame('Hamburg', $this->column('oxuser', 'OXCITY', (string) $firstId));
    }

    public function testStart_ExistingRegisteredEmail_RefusesWithAccountExistsAndLeavesTheAccountAlone(): void
    {
        $registered = $this->createFixtureUser('registered');
        $registered->oxuser__oxpassword = new Field('$2y$10$0123456789012345678901uVwxyzABCDEFGHIJKLMNOPQRSTUVWXY', Field::T_RAW);
        $registered->save();
        $basketId = $this->anonymousBasket();

        try {
            $this->service(new ContractOpeningTestHandler($this->contracts()))
                ->start($this->request($basketId, $this->details((string) $registered->getFieldData('oxusername'), city: 'Hijacked')));
            self::fail('a registered e-mail must log in');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::ACCOUNT_EXISTS, $e->errorCode);
        }

        self::assertSame('Bill City', $this->column('oxuser', 'OXCITY', (string) $registered->getId()));
        self::assertSame('', $this->column('oxuserbaskets', 'OXUSERID', $basketId), 'the basket stays unowned');
    }

    public function testStart_AnonymousBasketOfAnotherUser_IsRefusedAsBasketForbidden(): void
    {
        $owner = $this->createFixtureUser('owner');
        $basketId = $this->basketOf((string) $owner->getId());

        try {
            $this->service(new ContractOpeningTestHandler($this->contracts()))
                ->start($this->request($basketId, $this->details('guest_' . uniqid() . '@example.invalid')));
            self::fail('a basket with an owner cannot be claimed');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame('basket_forbidden', $e->errorCode);
        }

        self::assertSame((string) $owner->getId(), $this->column('oxuserbaskets', 'OXUSERID', $basketId));
    }

    public function testStart_GuestWithADeliveryAddress_StoresItForTheGuest(): void
    {
        $email = 'guest_' . uniqid() . '@example.invalid';
        $delivery = new GuestAddress(
            firstName: 'Charles',
            lastName: 'Babbage',
            street: 'Difference Road',
            streetNumber: '2',
            zipCode: '80331',
            city: 'Munich',
            countryId: self::GERMANY,
        );

        $basketId = $this->anonymousBasket();
        $this->service(new ContractOpeningTestHandler($this->contracts()))
            ->start($this->request($basketId, $this->details($email, delivery: $delivery)));

        $guestId = (string) $this->userIdOf($email);
        self::assertSame('Babbage', DatabaseProvider::getDb()->getOne('SELECT OXLNAME FROM oxaddress WHERE OXUSERID = ?', [$guestId]));
        if (DatabaseProvider::getDb()->getOne("SHOW COLUMNS FROM oxuserbaskets LIKE 'OEGQL_DELADDRESSID'")) {
            $addressId = DatabaseProvider::getDb()->getOne('SELECT OXID FROM oxaddress WHERE OXUSERID = ?', [$guestId]);
            self::assertSame($addressId, $this->column('oxuserbaskets', 'OEGQL_DELADDRESSID', $basketId));
        }
    }

    private function service(ContractFirstPaymentHandlerInterface $handler): HeadlessCheckoutService
    {
        $container = ContainerFactory::getInstance()->getContainer();

        return new HeadlessCheckoutService(
            new PaymentHandlerRegistry([$handler]),
            new ReturnResolverRegistry([]),
            $container->get(CheckoutBasketProviderInterface::class),
            new AnyUrlPolicy(),
            new HeadlessCheckoutScope(),
            $this->contracts(),
            $container->get(CheckoutReturnResponder::class),
            $this->createStub(PreviousCheckoutAttemptCleanerInterface::class),
            $container->get(GuestUserServiceInterface::class),
            $container->get(GuestBasketClaimInterface::class),
        );
    }

    private function contracts(): ContractRepositoryInterface
    {
        /** @var ContractRepositoryInterface $contracts */
        $contracts = ContainerFactory::getInstance()->getContainer()->get(ContractRepositoryInterface::class);

        return $contracts;
    }

    private function request(string $basketId, GuestDetails $guest): HeadlessStartRequest
    {
        return new HeadlessStartRequest(
            userId: self::ANONYMOUS_TOKEN_USER,
            basketId: $basketId,
            confirmTermsAndConditions: true,
            returnUrl: 'https://app.example.com/return',
            paymentId: self::PAYMENT_ID_OF_FIXTURE,
            guest: $guest,
        );
    }

    private function details(string $email, string $city = 'Berlin', ?GuestAddress $delivery = null): GuestDetails
    {
        return new GuestDetails($email, new GuestAddress(
            firstName: 'Ada',
            lastName: 'Lovelace',
            street: 'Analytical Way',
            streetNumber: '1',
            zipCode: '10115',
            city: $city,
            countryId: self::GERMANY,
        ), $delivery);
    }

    /**
     * What `basketCreate` + `basketAddItem` leave for an anonymous token: a
     * row without an owner (spike of 2026-10-22).
     */
    private function anonymousBasket(): string
    {
        return $this->basketOf('');
    }

    private function basketOf(string $ownerId): string
    {
        $this->createFixturePayment();
        $article = $this->createFixtureArticle();
        $row = oxNew(UserBasket::class);
        $row->setId('e2e_guest_' . substr(md5(uniqid('', true)), 0, 10));
        $row->oxuserbaskets__oxuserid = new Field($ownerId, Field::T_RAW);
        $row->oxuserbaskets__oxtitle = new Field('guest-checkout', Field::T_RAW);
        $row->oxuserbaskets__oxpublic = new Field(0, Field::T_RAW);
        $row->save();
        $row->addItemToBasket((string) $article->getId(), 1.0);

        return (string) $row->getId();
    }

    private function userIdOf(string $email): ?string
    {
        $id = DatabaseProvider::getDb()->getOne('SELECT OXID FROM oxuser WHERE OXUSERNAME = ?', [strtolower($email)]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function column(string $table, string $column, string $id): mixed
    {
        return DatabaseProvider::getDb()->getOne(sprintf('SELECT %s FROM %s WHERE OXID = ?', $column, $table), [$id]);
    }
}

final class AnyUrlPolicy implements ReturnUrlPolicyInterface
{
    public function assertAllowed(string $url): string
    {
        return $url;
    }

    public function isAllowed(string $url): bool
    {
        return true;
    }
}

/**
 * A provider handler without a PSP: opens the contract a real one opens for
 * the basket and the user it is handed.
 */
final class ContractOpeningTestHandler implements ContractFirstPaymentHandlerInterface
{
    public ?string $userIdSeen = null;

    public function __construct(private readonly ContractRepositoryInterface $contracts)
    {
    }

    public function getId(): string
    {
        return 'guesttest';
    }

    public function getName(): string
    {
        return 'Guest test';
    }

    public function supports(string $paymentMethodId): bool
    {
        return $paymentMethodId === 'e2e_sa_payment';
    }

    public function processPayment(PaymentContextInterface $context): PaymentHandlerResult
    {
        /** @var User $user */
        $user = $context->getUser();
        $this->userIdSeen = (string) $user->getId();

        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.4,
            'totalVat' => 1.6,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, $this->userIdSeen, $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-' . $contract->getId());
        $contract->transitionToPending();
        $contract->setProvider('guesttest', 'ps_1');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->contracts->save($contract);

        return PaymentHandlerResult::success(contractId: (string) $contract->getId());
    }

    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        return PaymentHandlerResult::success(contractId: $transactionId);
    }

    public function getFrontendConfig(): array
    {
        return [];
    }
}
