<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Math\Money\LineItemAmount;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

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

        $contents = $basket->getContents();
        if (!is_iterable($contents)) {
            return $items;
        }

        foreach ($contents as $basketItem) {
            if (!is_object($basketItem)) {
                continue;
            }

            $article = null;
            if (method_exists($basketItem, 'getArticle')) {
                $articleResult = $basketItem->getArticle();
                $article = is_object($articleResult) ? $articleResult : null;
            }

            $unitPrice = 0.0;
            $netPrice = 0.0;
            $vatValue = 0.0;
            if (method_exists($basketItem, 'getUnitPrice')) {
                $priceObj = $basketItem->getUnitPrice();
                if (is_object($priceObj)) {
                    if (method_exists($priceObj, 'getBruttoPrice')) {
                        $unitPrice = (float) $priceObj->getBruttoPrice();
                    }
                    if (method_exists($priceObj, 'getNettoPrice')) {
                        $netPrice = (float) $priceObj->getNettoPrice();
                    }
                    if (method_exists($priceObj, 'getVatValue')) {
                        $vatValue = (float) $priceObj->getVatValue();
                    }
                }
            }

            $amount = 1;
            if (method_exists($basketItem, 'getAmount')) {
                $amount = (int) $basketItem->getAmount();
            }

            $title = $this->extractArticleTitle($article);
            $productId = '';
            if ($article !== null && method_exists($article, 'getId')) {
                $productId = (string) $article->getId();
            }

            $lineAmount = LineItemAmount::forQuantity($unitPrice, $netPrice, $vatValue, $amount);

            $items[] = [
                'productId' => $productId,
                'title' => $title,
                'quantity' => $amount,
                'unitPrice' => $unitPrice,
                'totalPrice' => $lineAmount->totalPrice,
                'netPrice' => $lineAmount->netPrice,
                'vatValue' => $lineAmount->vatValue,
            ];
        }

        return $items;
    }

    private function extractArticleTitle(?object $article): string
    {
        if ($article === null) {
            return 'Product';
        }

        // OXID article title field access
        if (property_exists($article, 'oxarticles__oxtitle')) {
            /** @var object{value?: string}|null $titleField */
            $titleField = $article->oxarticles__oxtitle;
            if (is_object($titleField) && property_exists($titleField, 'value')) {
                return (string) $titleField->value;
            }
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

        // Extract basket-level and item-level discounts
        if (method_exists($basket, 'getDiscounts')) {
            $basketDiscounts = $basket->getDiscounts();
            if (is_array($basketDiscounts)) {
                foreach ($basketDiscounts as $discount) {
                    if (!is_object($discount)) {
                        continue;
                    }

                    $name = 'Discount';
                    if (property_exists($discount, 'sDiscount')) {
                        /** @phpstan-ignore-next-line */
                        $name = (string) $discount->sDiscount;
                    }

                    $amount = 0.0;
                    if (property_exists($discount, 'dDiscount')) {
                        /** @phpstan-ignore-next-line */
                        $amount = (float) $discount->dDiscount;
                    }

                    $discounts[] = [
                        'name' => $name,
                        'amount' => $amount,
                    ];
                }
            }
        }

        // Extract voucher discounts (OXID separates vouchers from basket discounts)
        if (method_exists($basket, 'getVouchers')) {
            $vouchers = $basket->getVouchers();
            if (is_array($vouchers)) {
                foreach ($vouchers as $voucher) {
                    if (!is_object($voucher)) {
                        continue;
                    }

                    $name = 'Voucher';
                    if (property_exists($voucher, 'sVoucherId')) {
                        /** @phpstan-ignore-next-line */
                        $name = 'Voucher: ' . (string) $voucher->sVoucherId;
                    }

                    $amount = 0.0;
                    if (property_exists($voucher, 'dVoucherdiscount')) {
                        /** @phpstan-ignore-next-line */
                        $amount = (float) $voucher->dVoucherdiscount;
                    }

                    if ($amount > 0.0) {
                        $discounts[] = [
                            'name' => $name,
                            'amount' => $amount,
                        ];
                    }
                }
            }
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
            if ($cost === null || !is_object($cost) || !method_exists($cost, 'getBruttoPrice')) {
                continue;
            }

            $bruttoPrice = (float) $cost->getBruttoPrice();
            if ($bruttoPrice <= 0) {
                continue;
            }

            $items[] = [
                'productId' => $config['id'],
                'title' => $config['title'],
                'quantity' => 1,
                'unitPrice' => $bruttoPrice,
                'totalPrice' => $bruttoPrice,
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
            if (is_object($price)) {
                if (method_exists($price, 'getBruttoPrice')) {
                    $totalGross = (float) $price->getBruttoPrice();
                }
                if (method_exists($price, 'getNettoPrice')) {
                    $totalNet = (float) $price->getNettoPrice();
                }
                if (method_exists($price, 'getVatValue')) {
                    $totalVat = (float) $price->getVatValue();
                }
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
