<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;

class ContractService implements ContractServiceInterface
{
    private ContractRepositoryInterface $contractRepository;

    public function __construct(ContractRepositoryInterface $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    public function createContract(
        string $userId,
        object $basket,
        array $conditionTypes = []
    ): PaymentContractInterface {
        $basketSnapshot = $this->createBasketSnapshot($basket);

        $contract = new PaymentContract(
            shopId: 1,
            userId: $userId,
            basketSnapshot: $basketSnapshot
        );

        if (empty($conditionTypes)) {
            $conditionTypes = [
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                ContractCondition::TYPE_FRAUD_CHECK,
            ];
        }

        foreach ($conditionTypes as $type) {
            $contract->addCondition(new ContractCondition($type));
        }

        $this->contractRepository->save($contract);

        return $contract;
    }

    public function findActiveContractByUser(string $userId): ?PaymentContractInterface
    {
        return $this->contractRepository->findActiveByUserId($userId);
    }

    public function cleanupExpiredContracts(): int
    {
        $expired = $this->contractRepository->findExpired();
        $count = 0;

        foreach ($expired as $contract) {
            $contract->expire();
            $this->contractRepository->save($contract);
            $count++;
        }

        return $count;
    }

    private function createBasketSnapshot(object $basket): BasketSnapshot
    {
        $items = $this->extractProductItems($basket);
        $discounts = $this->extractDiscounts($basket);

        // Add additional costs (shipping, payment fees, etc.)
        $items = array_merge($items, $this->extractAdditionalCosts($basket));

        // Get totals
        $totals = $this->extractTotals($basket);

        return BasketSnapshot::fromArray([
            'items' => $items,
            'discounts' => $discounts,
            'totalGross' => $totals['totalGross'],
            'totalNet' => $totals['totalNet'],
            'totalVat' => $totals['totalVat'],
            'currency' => $totals['currency'],
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractProductItems(object $basket): array
    {
        $items = [];

        if (!method_exists($basket, 'getContents')) {
            return $items;
        }

        foreach ($basket->getContents() as $basketItem) {
            $article = method_exists($basketItem, 'getArticle') ? $basketItem->getArticle() : null;
            $unitPrice = method_exists($basketItem, 'getUnitPrice')
                ? $basketItem->getUnitPrice()->getBruttoPrice()
                : 0.0;
            $amount = method_exists($basketItem, 'getAmount') ? (int) $basketItem->getAmount() : 1;

            $title = $this->extractArticleTitle($article);

            $items[] = [
                'productId' => $article !== null ? $article->getId() : '',
                'title' => $title,
                'quantity' => $amount,
                'unitPrice' => (float) $unitPrice,
                'totalPrice' => (float) ($unitPrice * $amount),
            ];
        }

        return $items;
    }

    private function extractArticleTitle(?object $article): string
    {
        if ($article === null) {
            return 'Product';
        }

        if (isset($article->oxarticles__oxtitle->value)) {
            return (string) $article->oxarticles__oxtitle->value;
        }

        if (method_exists($article, 'getTitle')) {
            return (string) $article->getTitle();
        }

        return 'Product';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractDiscounts(object $basket): array
    {
        $discounts = [];

        if (!method_exists($basket, 'getDiscounts')) {
            return $discounts;
        }

        $basketDiscounts = $basket->getDiscounts();
        if (!is_array($basketDiscounts)) {
            return $discounts;
        }

        foreach ($basketDiscounts as $discount) {
            $discounts[] = [
                'name' => $discount->sDiscount ?? 'Discount',
                'amount' => $discount->dDiscount ?? 0.0,
            ];
        }

        return $discounts;
    }

    /**
     * Extract additional costs (shipping, payment fees, wrapping, gift cards)
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractAdditionalCosts(object $basket): array
    {
        $items = [];

        if (!method_exists($basket, 'getCosts')) {
            return $items;
        }

        $costTypes = [
            'oxdelivery' => ['id' => 'shipping', 'title' => 'Shipping', 'flag' => 'isShipping'],
            'oxpayment' => ['id' => 'payment_fee', 'title' => 'Payment Fee', 'flag' => 'isPaymentFee'],
            'oxwrapping' => ['id' => 'gift_wrapping', 'title' => 'Gift Wrapping', 'flag' => 'isWrapping'],
            'oxgiftcard' => ['id' => 'gift_card', 'title' => 'Gift Card', 'flag' => 'isGiftCard'],
        ];

        foreach ($costTypes as $costKey => $config) {
            $cost = $basket->getCosts($costKey);
            if ($cost === null || $cost->getBruttoPrice() <= 0) {
                continue;
            }

            $items[] = [
                'productId' => $config['id'],
                'title' => $config['title'],
                'quantity' => 1,
                'unitPrice' => (float) $cost->getBruttoPrice(),
                'totalPrice' => (float) $cost->getBruttoPrice(),
                $config['flag'] => true,
            ];
        }

        return $items;
    }

    /**
     * @return array{totalGross: float, totalNet: float, totalVat: float, currency: string}
     */
    private function extractTotals(object $basket): array
    {
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalVat = 0.0;
        $currency = 'EUR';

        if (method_exists($basket, 'getPrice')) {
            $price = $basket->getPrice();
            if ($price !== null) {
                $totalGross = (float) $price->getBruttoPrice();
                $totalNet = (float) $price->getNettoPrice();
                $totalVat = (float) $price->getVatValue();
            }
        }

        if (method_exists($basket, 'getBasketCurrency')) {
            $basketCurrency = $basket->getBasketCurrency();
            if (is_object($basketCurrency) && property_exists($basketCurrency, 'name')) {
                /** @var object{name: string} $basketCurrency */
                $currency = (string) $basketCurrency->name;
            }
        }

        return [
            'totalGross' => $totalGross,
            'totalNet' => $totalNet,
            'totalVat' => $totalVat,
            'currency' => $currency,
        ];
    }
}
