<?php

namespace App\Services\Lazada\DTOs;

/**
 * One normalized record from GET /marketing/conversion/report.
 *
 * Field names follow the official doc (docId: marketing conversion report):
 * status is the Sub-Order status; estPayout / basePayout / bonusPayout are the
 * REAL payout amounts (total = base + bonus); subId1..subId6 are the tokens we
 * injected into the promotion URL. Reads are defensive (accepts the camelCase
 * documented names and their underscored variants) because the live sample was
 * empty at implementation time — verified via the real API wrapper shape
 * (result.data[]), with field values to be confirmed when conversions exist.
 */
class LazadaConversionRecord
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Read key with an underscored fallback (doc is ambiguous: subId1 vs sub_id1).
     */
    private function camel(string $key): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
        $value = $this->data[$key] ?? $this->data[$snake] ?? null;

        return $value === null ? '' : (string) $value;
    }

    private function dmy2Date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $clean = trim($value);

        return strtotime($clean) !== false
            ? date('Y-m-d H:i:s', strtotime($clean))
            : null;
    }

    public function getConversionTime(): ?string
    {
        return $this->dmy2Date($this->camel('conversionTime'));
    }

    public function getFulfilledTime(): ?string
    {
        return $this->dmy2Date($this->camel('fulfilledTime'));
    }

    public function getDeliveredTime(): ?string
    {
        return $this->dmy2Date($this->camel('deliveredTime'));
    }

    public function getReturnedTime(): ?string
    {
        return $this->dmy2Date($this->camel('returnedTime'));
    }

    public function getStatus(): string
    {
        return $this->camel('status');
    }

    public function getOfferName(): string
    {
        return $this->camel('offerName');
    }

    public function getOfferId(): string
    {
        return $this->camel('offerId');
    }

    public function getOfferType(): string
    {
        return $this->camel('offerType');
    }

    public function getPlatform(): string
    {
        return $this->camel('platform');
    }

    public function getCountry(): string
    {
        return $this->camel('country');
    }

    public function getOrderId(): string
    {
        return $this->camel('orderId');
    }

    public function getSubOrderId(): string
    {
        return $this->camel('subOrderId');
    }

    public function getSku(): string
    {
        return $this->camel('sku');
    }

    public function getSkuName(): string
    {
        return $this->camel('skuName');
    }

    public function getCategoryL1(): string
    {
        return $this->camel('categoryL1');
    }

    public function getSellerId(): string
    {
        return $this->camel('sellerId');
    }

    public function getSellerName(): string
    {
        return $this->camel('sellerName');
    }

    public function getBrandName(): string
    {
        return $this->camel('brandName');
    }

    public function getCurrency(): string
    {
        return $this->camel('currency');
    }

    public function getCommissionRate(): float
    {
        return (float) ($this->data['commissionRate'] ?? 0);
    }

    public function getBaseCommissionRate(): float
    {
        return (float) ($this->data['baseCommissionRate'] ?? 0);
    }

    public function getBonusCommissionRate(): float
    {
        return (float) ($this->data['bonusCommissionRate'] ?? 0);
    }

    public function getEstPayout(): float
    {
        return (float) ($this->data['estPayout'] ?? 0);
    }

    public function getBasePayout(): float
    {
        return (float) ($this->data['basePayout'] ?? 0);
    }

    public function getBonusPayout(): float
    {
        return (float) ($this->data['bonusPayout'] ?? 0);
    }

    public function getOrderAmt(): float
    {
        return (float) ($this->data['orderAmt'] ?? 0);
    }

    public function getNewUser(): string
    {
        return $this->camel('newUser');
    }

    public function getCommissionType(): string
    {
        return $this->camel('commissionType');
    }

    public function getSubId1(): string
    {
        return $this->camel('subId1');
    }

    public function getSubId2(): string
    {
        return $this->camel('subId2');
    }

    public function getSubId3(): string
    {
        return $this->camel('subId3');
    }

    public function getSubId4(): string
    {
        return $this->camel('subId4');
    }

    public function getSubId5(): string
    {
        return $this->camel('subId5');
    }
}