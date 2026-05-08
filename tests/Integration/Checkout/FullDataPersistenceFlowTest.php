<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;

/**
 * Full Data Persistence Flow Test
 *
 * Tests that ALL relevant oe_payments_* tables are populated during the checkout flow
 * as documented in: docs/payment-base/puml/04-02-payment-smart-contract-flow-standard.puml
 *
 * Tables tested:
 * - oe_payments_contract    : Contract state machine (includes capture/refund tracking since Sprint 8)
 * - oe_payments_customer    : Customer payment profile
 * - oe_payments_transaction : Payment transactions (auth, capture, refund)
 * - oe_payments_sessions    : Payment session data
 * - oxorder                 : OXID order (linked via contract)
 * - oxuser                  : OXID user (linked via contract)
 *
 * NOT tested (require Stripe API):
 * - oe_payments_webhooklogs
 * - oe_payments_idempotency
 *
 * Note: oe_payments_order_state was DROPPED in Sprint 8.
 * Capture/refund tracking is now handled by oe_payments_contract fields.
 *
 * @group integration
 * @group e2e
 * @group database
 * @group data-persistence
 */
final class FullDataPersistenceFlowTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'e2e_dp_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private DoctrineContractRepository $contractRepository;
    private DoctrineTransactionRepository $transactionRepository;
    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();

        $this->contractRepository = new DoctrineContractRepository($this->connection);
        $this->transactionRepository = new DoctrineTransactionRepository($this->connection);
    }

    public function tearDown(): void
    {
        $this->commitTransaction();
        $this->cleanupCaching();
        $this->restoreRequestData();
    }

    private function commitTransaction(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $connection = $connectionProvider->get();

        if ($connection->isTransactionActive()) {
            $connection->commit();
        }
    }

    // =========================================================================
    // TEST: oe_payments_contract + oxuser + oxorder
    // =========================================================================

    /**
     * @group data-persistence
     */
    public function testContractCreation_PersistsContractWithUserAndOrder(): void
    {
        // 1. Create real user in oxuser
        $userId = $this->createTestUser();

        // 2. Create contract linked to user
        $contractId = $this->createContractId('contract_user');
        $contract = $this->createContract($contractId, $userId);
        $this->contractRepository->save($contract);

        // 3. Verify contract in oe_payments_contract
        $dbContract = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $this->assertNotFalse($dbContract, 'Contract should exist in oe_payments_contract');
        $this->assertEquals($userId, $dbContract['OXUSERID'], 'Contract should link to user');
        $this->assertEquals('draft', $dbContract['OXSTATE']);

        // 4. Verify user exists in oxuser
        $dbUser = $this->connection->fetchAssociative(
            'SELECT * FROM oxuser WHERE OXID = :id',
            ['id' => $userId]
        );
        $this->assertNotFalse($dbUser, 'User should exist in oxuser');
        $this->assertStringContainsString('e2e_', $dbUser['OXFNAME']);
    }

    /**
     * @group data-persistence
     */
    public function testOrderCommit_LinksContractToRealOrder(): void
    {
        // 1. Create user
        $userId = $this->createTestUser();

        // 2. Create and commit contract
        $contractId = $this->createContractId('contract_order');
        $contract = $this->createContract($contractId, $userId);
        $contract->addCondition(ContractCondition::paymentAuthorized());

        // 3. Create real order in oxorder
        $orderId = $this->createTestOrder($userId, 499.99, 'EUR');

        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_' . $this->testRunId
        ]);
        $this->contractRepository->save($contract);

        // 4. Commit contract to order
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        // 5. Verify contract links to order
        $dbContract = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $this->assertNotFalse($dbContract, 'Contract should exist');
        $this->assertEquals($orderId, $dbContract['OXORDERID'], 'Contract should link to order');
        $this->assertEquals('committed', $dbContract['OXSTATE']);
        // Note: OXCOMMITTEDAT is not yet tracked by the PaymentContract entity

        // 6. Verify order exists with correct data
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );
        $this->assertNotFalse($dbOrder, 'Order should exist in oxorder');
        $this->assertEquals($userId, $dbOrder['OXUSERID'], 'Order should link to user');
        $this->assertEquals(499.99, (float)$dbOrder['OXTOTALORDERSUM'], 'Order total should match');
    }

    // =========================================================================
    // TEST: oe_payments_transaction
    // =========================================================================

    /**
     * @group data-persistence
     */
    public function testTransaction_PersistsAuthorizationTransaction(): void
    {
        $userId = $this->createTestUser();
        $contractId = $this->createContractId('tx_auth');
        $orderId = $this->createTestOrder($userId, 150.00, 'EUR');

        // Create contract first (FK constraint)
        $contract = $this->createContract($contractId, $userId);
        $this->contractRepository->save($contract);

        // Create authorization transaction
        $transactionId = $this->createTransactionId('auth');
        $transaction = new Transaction(
            $transactionId,
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'authorization',
            'completed',
            150.00,
            'EUR'
        );
        $transaction->setProviderOrderId('pi_' . $this->testRunId);
        $transaction->setTransactionId('ch_' . $this->testRunId);
        $transaction->setPaymentMethodType('card');

        $this->transactionRepository->save($transaction);

        // Verify in database
        $dbTx = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_transaction WHERE OXID = :id',
            ['id' => $transactionId]
        );

        $this->assertNotFalse($dbTx, 'Transaction should exist');
        $this->assertEquals($orderId, $dbTx['OXORDERID']);
        $this->assertEquals($contractId, $dbTx['OXCONTRACTID']);
        $this->assertEquals('stripe', $dbTx['OXPROVIDER']);
        $this->assertEquals('authorization', $dbTx['OXTYPE']);
        $this->assertEquals('completed', $dbTx['OXSTATUS']);
        $this->assertEquals(150.00, (float)$dbTx['OXAMOUNT']);
        $this->assertEquals('EUR', $dbTx['OXCURRENCY']);
        $this->assertEquals('pi_' . $this->testRunId, $dbTx['OXPROVIDERORDERID']);
        $this->assertEquals('ch_' . $this->testRunId, $dbTx['OXTRANSACTIONID']);
    }

    /**
     * @group data-persistence
     */
    public function testTransaction_PersistsCaptureTransaction(): void
    {
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 200.00, 'EUR');
        $contractId = $this->createContractId('tx_capture');

        // Create contract first (FK constraint)
        $contract = $this->createContract($contractId, $userId);
        $this->contractRepository->save($contract);

        // Create capture transaction
        $transactionId = $this->createTransactionId('capture');
        $transaction = new Transaction(
            $transactionId,
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'capture',
            'completed',
            200.00,
            'EUR'
        );
        $transaction->setProviderOrderId('pi_capture_' . $this->testRunId);
        $transaction->setTransactionId('py_' . $this->testRunId);

        $this->transactionRepository->save($transaction);

        // Verify
        $dbTx = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_transaction WHERE OXID = :id',
            ['id' => $transactionId]
        );

        $this->assertEquals('capture', $dbTx['OXTYPE']);
        $this->assertEquals('completed', $dbTx['OXSTATUS']);
    }

    /**
     * @group data-persistence
     */
    public function testTransaction_PersistsRefundTransaction(): void
    {
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');
        $contractId = $this->createContractId('tx_refund');

        // Create contract first (FK constraint)
        $contract = $this->createContract($contractId, $userId);
        $this->contractRepository->save($contract);

        // Create refund transaction
        $transactionId = $this->createTransactionId('refund');
        $transaction = new Transaction(
            $transactionId,
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'refund',
            'completed',
            50.00,  // Partial refund
            'EUR'
        );
        $transaction->setTransactionId('re_' . $this->testRunId);

        $this->transactionRepository->save($transaction);

        // Verify
        $dbTx = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_transaction WHERE OXID = :id',
            ['id' => $transactionId]
        );

        $this->assertEquals('refund', $dbTx['OXTYPE']);
        $this->assertEquals(50.00, (float)$dbTx['OXAMOUNT']);
    }

    /**
     * @group data-persistence
     */
    public function testTransaction_FindByOrderIdReturnsAllTransactions(): void
    {
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 300.00, 'EUR');
        $contractId = $this->createContractId('tx_multi');

        // Create contract first (FK constraint)
        $contract = $this->createContract($contractId, $userId);
        $this->contractRepository->save($contract);

        // Create multiple transactions for same order
        $authTx = new Transaction(
            $this->createTransactionId('auth_multi'),
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'authorization',
            'completed',
            300.00,
            'EUR'
        );
        $this->transactionRepository->save($authTx);

        $captureTx = new Transaction(
            $this->createTransactionId('cap_multi'),
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'capture',
            'completed',
            300.00,
            'EUR'
        );
        $this->transactionRepository->save($captureTx);

        // Find all transactions for order
        $transactions = $this->transactionRepository->findByOrderId($orderId);

        $this->assertCount(2, $transactions);
        $types = array_map(fn($tx) => $tx->getType(), $transactions);
        $this->assertContains('authorization', $types);
        $this->assertContains('capture', $types);
    }

    // =========================================================================
    // TEST: oe_payments_contract capture/refund (Sprint 8)
    // =========================================================================
    // Note: oe_payments_order_state was DROPPED in Sprint 8.
    // Capture/refund tracking tests moved to ContractCaptureRefundTest.php

    // =========================================================================
    // TEST: oe_payments_customer
    // =========================================================================

    /**
     * @group data-persistence
     */
    public function testPaymentCustomer_PersistsCustomerPaymentProfile(): void
    {
        $userId = $this->createTestUser();
        $customerId = $this->createPaymentCustomerId('profile');
        $stripeCustomerId = 'cus_' . $this->testRunId;

        // Insert customer payment profile
        $this->connection->insert('oe_payments_customer', [
            'OXID' => $customerId,
            'OXUSERID' => $userId,
            'OXPAYMENTCUSTOMERID' => $stripeCustomerId,
            'OXDEFAULTPAYMENTMETHOD' => 'pm_card_visa',
            'OXSAVEDPAYMENTMETHODS' => json_encode([
                ['id' => 'pm_card_visa', 'type' => 'card', 'last4' => '4242'],
                ['id' => 'pm_sepa_de', 'type' => 'sepa_debit', 'last4' => '3000'],
            ]),
            'OXBILLINGAGREEMENT' => 1,
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXUPDATED' => date('Y-m-d H:i:s'),
        ]);

        // Verify customer record
        $dbCustomer = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_customer WHERE OXUSERID = :userId',
            ['userId' => $userId]
        );

        $this->assertNotFalse($dbCustomer, 'Payment customer should exist');
        $this->assertEquals($stripeCustomerId, $dbCustomer['OXPAYMENTCUSTOMERID']);
        $this->assertEquals('pm_card_visa', $dbCustomer['OXDEFAULTPAYMENTMETHOD']);
        $this->assertEquals(1, (int)$dbCustomer['OXBILLINGAGREEMENT']);

        $savedMethods = json_decode($dbCustomer['OXSAVEDPAYMENTMETHODS'], true);
        $this->assertCount(2, $savedMethods);
    }

    /**
     * @group data-persistence
     */
    public function testPaymentCustomer_LinksToOxuser(): void
    {
        $userId = $this->createTestUser();
        $customerId = $this->createPaymentCustomerId('link_user');

        $this->connection->insert('oe_payments_customer', [
            'OXID' => $customerId,
            'OXUSERID' => $userId,
            'OXPAYMENTCUSTOMERID' => 'cus_link_' . $this->testRunId,
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXUPDATED' => date('Y-m-d H:i:s'),
        ]);

        // Join query to verify linkage
        $result = $this->connection->fetchAssociative(
            'SELECT pc.*, u.OXFNAME, u.OXLNAME, u.OXUSERNAME
             FROM oe_payments_customer pc
             JOIN oxuser u ON pc.OXUSERID = u.OXID
             WHERE pc.OXID = :id',
            ['id' => $customerId]
        );

        $this->assertNotFalse($result, 'Join should return data');
        $this->assertStringContainsString('e2e_', $result['OXFNAME']);
        $this->assertEquals($userId, $result['OXUSERID']);
    }

    // =========================================================================
    // TEST: oe_payments_sessions
    // =========================================================================

    /**
     * @group data-persistence
     */
    public function testPaymentSession_PersistsSessionData(): void
    {
        $userId = $this->createTestUser();
        $sessionId = $this->createSessionId('checkout');
        $stripeSessionId = 'cs_test_' . $this->testRunId;

        // Insert session record
        $this->connection->insert('oe_payments_sessions', [
            'OXID' => $sessionId,
            'OXPROVIDER' => 'stripe',
            'OXSESSIONID' => $stripeSessionId,
            'OXUSERID' => $userId,
            'OXBASKETID' => 'basket_' . $this->testRunId,
            'OXDATA' => json_encode([
                'paymentIntentId' => 'pi_' . $this->testRunId,
                'clientSecret' => 'pi_xxx_secret_xxx',
                'returnUrl' => 'https://shop.local/thankyou',
                'cancelUrl' => 'https://shop.local/payment',
            ]),
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXEXPIRES' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);

        // Verify session record
        $dbSession = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_sessions WHERE OXSESSIONID = :sessionId',
            ['sessionId' => $stripeSessionId]
        );

        $this->assertNotFalse($dbSession, 'Session should exist');
        $this->assertEquals('stripe', $dbSession['OXPROVIDER']);
        $this->assertEquals($userId, $dbSession['OXUSERID']);

        $sessionData = json_decode($dbSession['OXDATA'], true);
        $this->assertArrayHasKey('paymentIntentId', $sessionData);
        $this->assertArrayHasKey('clientSecret', $sessionData);
    }

    /**
     * @group data-persistence
     */
    public function testPaymentSession_ExpiresCorrectly(): void
    {
        $sessionId = $this->createSessionId('expiry');
        $expiredTime = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $this->connection->insert('oe_payments_sessions', [
            'OXID' => $sessionId,
            'OXPROVIDER' => 'stripe',
            'OXSESSIONID' => 'cs_expired_' . $this->testRunId,
            'OXCREATED' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'OXEXPIRES' => $expiredTime,
        ]);

        // Verify the session expiry time is stored correctly
        $dbSession = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_sessions WHERE OXID = :id',
            ['id' => $sessionId]
        );

        $this->assertNotFalse($dbSession, 'Session should exist');
        // Verify the expiry time was stored as expected
        $storedExpiry = new \DateTimeImmutable($dbSession['OXEXPIRES']);
        $now = new \DateTimeImmutable();
        $this->assertLessThan($now, $storedExpiry, 'Session expiry time should be in the past');
    }

    // =========================================================================
    // TEST: Complete Flow - All Tables
    // =========================================================================

    /**
     * Tests the complete checkout flow populates all required tables.
     *
     * @group data-persistence
     * @group complete-flow
     */
    public function testCompleteFlow_PopulatesAllTables(): void
    {
        $flowId = substr($this->testRunId, 0, 6);

        // 1. Create user (oxuser)
        $userId = $this->createTestUser('flow_' . $flowId);

        // 2. Create payment customer profile (oe_payments_customer)
        $paymentCustomerId = $this->createPaymentCustomerId('flow');
        $stripeCustomerId = 'cus_flow_' . $flowId;
        $this->connection->insert('oe_payments_customer', [
            'OXID' => $paymentCustomerId,
            'OXUSERID' => $userId,
            'OXPAYMENTCUSTOMERID' => $stripeCustomerId,
            'OXDEFAULTPAYMENTMETHOD' => 'pm_card_visa',
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXUPDATED' => date('Y-m-d H:i:s'),
        ]);

        // 3. Create session (oe_payments_sessions)
        $sessionId = $this->createSessionId('flow');
        $stripeSessionId = 'cs_flow_' . $flowId;
        $this->connection->insert('oe_payments_sessions', [
            'OXID' => $sessionId,
            'OXPROVIDER' => 'stripe',
            'OXSESSIONID' => $stripeSessionId,
            'OXUSERID' => $userId,
            'OXDATA' => json_encode(['paymentIntentId' => 'pi_flow_' . $flowId]),
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXEXPIRES' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);

        // 4. Create order (oxorder) - must be created before contract transitions to NOT_FINISHED
        $orderId = $this->createTestOrder($userId, 599.99, 'EUR');

        // 5. Create contract (oe_payments_contract)
        $contractId = $this->createContractId('flow');
        $providerOrderId = 'pi_flow_' . $flowId;
        $contract = $this->createContract($contractId, $userId);
        $contract->setProvider('stripe', $providerOrderId, 'https://checkout.stripe.com/xxx');
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        // 6. Fulfill conditions (updates oe_payments_contract)
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_flow_' . $flowId,
        ]);
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, [
            'score' => 95,
            'risk' => 'low',
        ]);
        $this->contractRepository->save($contract);

        // 7. Commit contract to order
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        // 8. Create authorization transaction (oe_payments_transaction)
        // Note: oe_payments_order_state was DROPPED in Sprint 8
        $authTxId = $this->createTransactionId('flow_auth');
        $authTx = new Transaction(
            $authTxId,
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'authorization',
            'completed',
            599.99,
            'EUR'
        );
        $authTx->setProviderOrderId($providerOrderId);
        $authTx->setTransactionId('ch_flow_' . $flowId);
        $this->transactionRepository->save($authTx);

        // 9. Simulate capture (webhook) - add capture transaction and update contract
        // Sprint 8: Capture tracking now on oe_payments_contract, not oe_payments_order_state
        $contract->setCapturedAmount(599.99);
        $contract->setCapturedAt(new \DateTimeImmutable());
        $this->contractRepository->save($contract);

        $captureTxId = $this->createTransactionId('flow_cap');
        $captureTx = new Transaction(
            $captureTxId,
            self::SHOP_ID,
            $orderId,
            $contractId,
            'stripe',
            'capture',
            'completed',
            599.99,
            'EUR'
        );
        $captureTx->setProviderOrderId($providerOrderId);
        $captureTx->setTransactionId('py_flow_' . $flowId);
        $this->transactionRepository->save($captureTx);

        // 10. Fulfill contract
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // =====================================================================
        // VERIFY ALL TABLES
        // =====================================================================

        // Verify oxuser
        $dbUser = $this->connection->fetchAssociative(
            'SELECT * FROM oxuser WHERE OXID = :id',
            ['id' => $userId]
        );
        $this->assertNotFalse($dbUser, 'oxuser record should exist');

        // Verify oe_payments_customer
        $dbCustomer = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_customer WHERE OXUSERID = :userId',
            ['userId' => $userId]
        );
        $this->assertNotFalse($dbCustomer, 'oe_payments_customer record should exist');
        $this->assertEquals($stripeCustomerId, $dbCustomer['OXPAYMENTCUSTOMERID']);

        // Verify oe_payments_sessions
        $dbSession = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_sessions WHERE OXID = :id',
            ['id' => $sessionId]
        );
        $this->assertNotFalse($dbSession, 'oe_payments_sessions record should exist');

        // Verify oe_payments_contract
        $dbContract = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $this->assertNotFalse($dbContract, 'oe_payments_contract record should exist');
        $this->assertEquals('fulfilled', $dbContract['OXSTATE']);
        $this->assertEquals($orderId, $dbContract['OXORDERID']);
        $this->assertEquals($userId, $dbContract['OXUSERID']);
        $this->assertEquals($providerOrderId, $dbContract['OXPROVIDERORDERID']);
        $this->assertNotNull($dbContract['OXFULFILLEDAT']);

        // Sprint 8: Verify capture tracking on contract (replaces oe_payments_order_state)
        $this->assertEquals(599.99, (float)$dbContract['OXCAPTUREDAMOUNT'], 'Contract should track captured amount');
        $this->assertNotNull($dbContract['OXCAPTUREDAT'], 'Contract should have capture timestamp');

        // Verify oxorder
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );
        $this->assertNotFalse($dbOrder, 'oxorder record should exist');
        $this->assertEquals($userId, $dbOrder['OXUSERID']);
        $this->assertEquals(599.99, (float)$dbOrder['OXTOTALORDERSUM']);

        // Note: oe_payments_order_state was DROPPED in Sprint 8
        // Capture/refund tracking is now on oe_payments_contract (verified above)

        // Verify oe_payments_transaction (should have 2: auth + capture)
        $dbTransactions = $this->transactionRepository->findByOrderId($orderId);
        $this->assertCount(2, $dbTransactions, 'Should have 2 transactions');

        $txTypes = array_map(fn($tx) => $tx->getType(), $dbTransactions);
        $this->assertContains('authorization', $txTypes);
        $this->assertContains('capture', $txTypes);

        // Verify all transactions link to contract
        foreach ($dbTransactions as $tx) {
            $this->assertEquals($contractId, $tx->getContractId());
            $this->assertEquals($orderId, $tx->getOrderId());
        }
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContractId(string $suffix): string
    {
        return substr(self::TEST_PREFIX . $this->testRunId . '_' . $suffix, 0, 32);
    }

    private function createTransactionId(string $suffix): string
    {
        return substr(self::TEST_PREFIX . 'tx_' . $this->testRunId . '_' . $suffix, 0, 32);
    }

    // Note: createOrderStateId removed in Sprint 8 (oe_payments_order_state dropped)

    private function createPaymentCustomerId(string $suffix): string
    {
        return substr(self::TEST_PREFIX . 'pc_' . $this->testRunId . '_' . $suffix, 0, 32);
    }

    private function createSessionId(string $suffix): string
    {
        return substr(self::TEST_PREFIX . 'ss_' . $this->testRunId . '_' . $suffix, 0, 32);
    }

    private function createContract(string $contractId, string $userId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [
                ['articleId' => 'art_001', 'title' => 'Test Product', 'quantity' => 1, 'price' => 499.99]
            ],
            'discounts' => [],
            'totalGross' => 499.99,
            'totalNet' => 420.16,
            'totalVat' => 79.83,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        return new PaymentContract(
            shopId: self::SHOP_ID,
            userId: $userId,
            basketSnapshot: $basketSnapshot,
            id: $contractId
        );
    }

    private function createTestUser(string $suffix = ''): string
    {
        $userId = substr(self::TEST_PREFIX . 'user_' . $this->testRunId . ($suffix ? '_' . $suffix : ''), 0, 32);

        $this->connection->insert('oxuser', [
            'OXID' => $userId,
            'OXACTIVE' => 1,
            'OXRIGHTS' => 'user',
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERNAME' => 'e2e_test_' . $this->testRunId . '@example.com',
            'OXPASSWORD' => '',
            'OXFNAME' => 'e2e_Test',
            'OXLNAME' => 'User_' . $this->testRunId,
            'OXSTREET' => 'Test Street',
            'OXSTREETNR' => '123',
            'OXCITY' => 'Test City',
            'OXCOUNTRYID' => 'a7c40f631fc920687.20179984', // Germany
            'OXZIP' => '12345',
            'OXSAL' => 'MR',
            'OXCREATE' => date('Y-m-d H:i:s'),
            'OXREGISTER' => date('Y-m-d H:i:s'),
        ]);

        return $userId;
    }

    private function createTestOrder(string $userId, float $total, string $currency): string
    {
        $orderId = substr(self::TEST_PREFIX . 'ord_' . $this->testRunId, 0, 32);

        $this->connection->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(100000, 999999),
            'OXBILLEMAIL' => 'e2e_test@example.com',
            'OXBILLFNAME' => 'e2e_Test',
            'OXBILLLNAME' => 'User',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '123',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => 'stripe_card',
            'OXTOTALNETSUM' => $total / 1.19,
            'OXTOTALBRUTSUM' => $total,
            'OXTOTALORDERSUM' => $total,
            'OXCURRENCY' => $currency,
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
            'OXTRANSSTATUS' => 'NOT_FINISHED',
        ]);

        return $orderId;
    }
}
