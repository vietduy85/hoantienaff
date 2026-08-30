<?php

namespace App\Services\TikTok\DTOs;

class TikTokOrder
{
    public function __construct(
        private readonly string $orderId,
        private readonly ?string $skuId = null,
        private readonly ?string $productId = null,
        private readonly ?string $productName = null,
        private readonly ?float $price = null,
        private readonly ?int $quantity = null,
        private readonly ?int $refundedQuantity = null,
        private readonly ?int $returnedQuantity = null,
        private readonly ?bool $fullyRefunded = null,
        private readonly ?string $shopName = null,
        private readonly ?string $settlementStatus = null,
        private readonly ?int $status = null,
        private readonly ?string $contentType = null,
        private readonly ?string $contentId = null,
        private readonly ?string $subId = null,
        private readonly ?string $sub1 = null,
        private readonly ?string $sub2 = null,
        private readonly ?string $sub3 = null,
        private readonly ?string $sub4 = null,
        private readonly ?string $commissionModel = null,
        private readonly ?float $standardCommissionRate = null,
        private readonly ?float $commissionRate = null,
        private readonly ?float $commissionBonusRate = null,
        private readonly ?float $commissionGmv = null,
        private readonly ?float $estStandardCommission = null,
        private readonly ?float $estBonusCommission = null,
        private readonly ?float $estCommission = null,
        private readonly ?float $actualCommission = null,
        private readonly ?float $actualStandardCommission = null,
        private readonly ?float $actualBonusCommission = null,
        private readonly ?float $shopAdsCommissionRate = null,
        private readonly ?float $estShopAdsCommission = null,
        private readonly ?float $actualShopAdsCommission = null,
        private readonly ?float $actualCreatorCommissionRewardFee = null,
        private readonly ?string $currency = null,
        private readonly ?string $timeCreated = null,
        private readonly ?string $timeDelivered = null,
        private readonly ?int $createTime = null,
        private readonly ?int $updateTime = null,
        private readonly ?int $ttOrderStatus = null,
        private readonly ?string $paymentStatus = null,
        private readonly ?string $pit = null,
        private readonly array $raw = [],
    ) {}

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getSkuId(): ?string
    {
        return $this->skuId;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getRefundedQuantity(): ?int
    {
        return $this->refundedQuantity;
    }

    public function getReturnedQuantity(): ?int
    {
        return $this->returnedQuantity;
    }

    public function isFullyRefunded(): ?bool
    {
        return $this->fullyRefunded;
    }

    public function getShopName(): ?string
    {
        return $this->shopName;
    }

    public function getSettlementStatus(): ?string
    {
        return $this->settlementStatus;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    public function getSubId(): ?string
    {
        return $this->subId;
    }

    public function getSub1(): ?string
    {
        return $this->sub1;
    }

    public function getSub2(): ?string
    {
        return $this->sub2;
    }

    public function getSub3(): ?string
    {
        return $this->sub3;
    }

    public function getSub4(): ?string
    {
        return $this->sub4;
    }

    public function getCommissionModel(): ?string
    {
        return $this->commissionModel;
    }

    public function getStandardCommissionRate(): ?float
    {
        return $this->standardCommissionRate;
    }

    public function getCommissionRate(): ?float
    {
        return $this->commissionRate;
    }

    public function getCommissionBonusRate(): ?float
    {
        return $this->commissionBonusRate;
    }

    public function getCommissionGmv(): ?float
    {
        return $this->commissionGmv;
    }

    public function getEstStandardCommission(): ?float
    {
        return $this->estStandardCommission;
    }

    public function getEstBonusCommission(): ?float
    {
        return $this->estBonusCommission;
    }

    /**
     * Total estimated commission (standard + bonus + shop_ads).
     */
    public function getEstCommission(): ?float
    {
        return $this->estCommission;
    }

    public function getActualCommission(): ?float
    {
        return $this->actualCommission;
    }

    public function getActualStandardCommission(): ?float
    {
        return $this->actualStandardCommission;
    }

    public function getActualBonusCommission(): ?float
    {
        return $this->actualBonusCommission;
    }

    public function getShopAdsCommissionRate(): ?float
    {
        return $this->shopAdsCommissionRate;
    }

    public function getEstShopAdsCommission(): ?float
    {
        return $this->estShopAdsCommission;
    }

    public function getActualShopAdsCommission(): ?float
    {
        return $this->actualShopAdsCommission;
    }

    public function getActualCreatorCommissionRewardFee(): ?float
    {
        return $this->actualCreatorCommissionRewardFee;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getTimeCreated(): ?string
    {
        return $this->timeCreated;
    }

    public function getTimeDelivered(): ?string
    {
        return $this->timeDelivered;
    }

    public function getCreateTime(): ?int
    {
        return $this->createTime;
    }

    public function getUpdateTime(): ?int
    {
        return $this->updateTime;
    }

    public function getTtOrderStatus(): ?int
    {
        return $this->ttOrderStatus;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function getPit(): ?string
    {
        return $this->pit;
    }

    public function isSettled(): bool
    {
        return $this->status === 2
            || strtoupper((string) $this->settlementStatus) === 'SETTLED';
    }

    public function isRefunded(): bool
    {
        return $this->status === 3
            || strtoupper((string) $this->settlementStatus) === 'REFUNDED';
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public static function fromArray(array $data): static
    {
        return new static(
            orderId: (string) ($data['order_id'] ?? $data['id'] ?? ''),
            skuId: $data['sku_id'] ?? null,
            productId: $data['product_id'] ?? null,
            productName: $data['product_name'] ?? $data['name'] ?? null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            quantity: isset($data['quantity']) ? (int) $data['quantity'] : null,
            refundedQuantity: isset($data['refunded_quantity']) ? (int) $data['refunded_quantity'] : null,
            returnedQuantity: isset($data['returned_quantity']) ? (int) $data['returned_quantity'] : null,
            fullyRefunded: isset($data['fully_refunded']) ? (bool) (int) $data['fully_refunded'] : null,
            shopName: $data['shop_name'] ?? null,
            settlementStatus: $data['settlement_status'] ?? null,
            status: isset($data['status']) ? (int) $data['status'] : null,
            contentType: $data['content_type'] ?? null,
            contentId: $data['content_id'] ?? null,
            subId: array_key_exists('sub_id', $data) ? (is_string($data['sub_id']) ? $data['sub_id'] : (string) $data['sub_id']) : null,
            sub1: $data['sub1'] ?? null,
            sub2: $data['sub2'] ?? null,
            sub3: $data['sub3'] ?? null,
            sub4: $data['sub4'] ?? null,
            commissionModel: $data['commission_model'] ?? null,
            standardCommissionRate: isset($data['standard_commission_rate']) && $data['standard_commission_rate'] !== '' ? (float) $data['standard_commission_rate'] / 100 : null,
            commissionRate: isset($data['commission_rate']) && $data['commission_rate'] !== '' ? (float) $data['commission_rate'] / 100 : null,
            commissionBonusRate: isset($data['commission_bonus_rate']) && $data['commission_bonus_rate'] !== '' ? (float) $data['commission_bonus_rate'] / 100 : null,
            commissionGmv: isset($data['commission_gmv']) ? (float) $data['commission_gmv'] : null,
            estStandardCommission: isset($data['est_standard_commission']) ? (float) $data['est_standard_commission'] : null,
            estBonusCommission: isset($data['est_bonus_commission']) && $data['est_bonus_commission'] !== null ? (float) $data['est_bonus_commission'] : null,
            estCommission: isset($data['est_commission']) ? (float) $data['est_commission'] : null,
            actualCommission: isset($data['actual_commission']) && $data['actual_commission'] !== null ? (float) $data['actual_commission'] : null,
            actualStandardCommission: isset($data['actual_standard_commission']) && $data['actual_standard_commission'] !== null ? (float) $data['actual_standard_commission'] : null,
            actualBonusCommission: isset($data['actual_bonus_commission']) && $data['actual_bonus_commission'] !== null ? (float) $data['actual_bonus_commission'] : null,
            shopAdsCommissionRate: isset($data['shop_ads_commission_rate']) && $data['shop_ads_commission_rate'] !== '' ? (float) $data['shop_ads_commission_rate'] / 100 : null,
            estShopAdsCommission: isset($data['est_shop_ads_commission']) && $data['est_shop_ads_commission'] !== null ? (float) $data['est_shop_ads_commission'] : null,
            actualShopAdsCommission: isset($data['actual_shop_ads_commission']) && $data['actual_shop_ads_commission'] !== null ? (float) $data['actual_shop_ads_commission'] : null,
            actualCreatorCommissionRewardFee: isset($data['actual_creator_commission_reward_fee']) && $data['actual_creator_commission_reward_fee'] !== null ? (float) $data['actual_creator_commission_reward_fee'] : null,
            currency: $data['currency'] ?? null,
            timeCreated: $data['time_created'] ?? $data['created_at'] ?? $data['order_time'] ?? null,
            timeDelivered: $data['time_delivered'] ?? $data['completed_at'] ?? null,
            createTime: isset($data['create_time']) ? (int) $data['create_time'] : null,
            updateTime: isset($data['update_time']) ? (int) $data['update_time'] : null,
            ttOrderStatus: isset($data['tt_order_status']) ? (int) $data['tt_order_status'] : null,
            paymentStatus: $data['payment_status'] ?? null,
            pit: isset($data['pit']) && $data['pit'] !== null ? (string) $data['pit'] : null,
            raw: $data,
        );
    }

    /**
     * Map RioHub order fields to affiliate_order_items column array.
     *
     * @param  string  $username  The platform username (from sub1 lookup).
     * @param  int|null  $userId  The resolved user_id (null if not found).
     * @param  string  $importBatch  Import batch code (Ymd_His).
     */
    public function toDatabaseArray(string $username, ?int $userId, string $importBatch): array
    {
        return [
            // Order & Checkout
            'order_id'                => $this->orderId,
            'order_status'            => $this->settlementStatus ?? $this->mapStatus($this->status),
            'checkout_id'             => '',
            'content_id'              => $this->contentId !== null ? (string) $this->contentId : null,

            // Timestamps
            'ordered_at'              => $this->timeCreated ?? ($this->createTime ? date('Y-m-d H:i:s', $this->createTime) : null),
            'completed_at'            => $this->timeDelivered,
            'clicked_at'              => null,

            // Shop
            'shop_name'               => $this->shopName ?? '',
            'shop_id'                 => 0,
            'shop_type'               => null,

            // Item
            'item_id'                 => (int) ($this->productId ?? 0),
            'item_name'               => $this->productName ?? '',
            'model_id'                => (int) ($this->skuId ?? 0),
            'product_type'            => null,
            'promotion_id'            => null,

            // Categories
            'category_l1'             => null,
            'category_l2'             => null,
            'category_l3'             => null,

            // Pricing & Quantity
            'item_price'              => $this->price ?? 0,
            'quantity'                => $this->quantity ?? 0,
            'order_amount'            => $this->commissionGmv ?? 0,
            'refund_amount'           => ($this->refundedQuantity ?? 0) * ($this->price ?? 0),

            // Commission Type
            'commission_type'         => $this->commissionModel ?? '',
            'campaign_partner'        => null,

            // Commission Rates & Amounts
            'shopee_commission_rate'  => $this->standardCommissionRate ?? 0,
            'shopee_commission'       => $this->estStandardCommission ?? 0,
            'seller_commission_rate'  => 0,
            'xtra_commission'         => $this->estBonusCommission ?? 0,
            'total_product_commission'=> $this->estCommission ?? 0,
            'order_commission_shopee' => 0,
            'order_commission_seller' => 0,
            'total_order_commission'  => $this->estCommission ?? 0,

            // MCN
            'mcn_name'                => null,
            'mcn_contract_code'       => null,
            'mcn_management_fee_rate' => 0,
            'mcn_management_fee'      => 0,

            // Net Commission
            'agreed_commission_rate'  => $this->commissionRate ?? 0,
            'net_commission'          => $this->actualCommission ?? $this->estCommission ?? 0,

            // Status & Notes
            'affiliate_status'        => $this->mapStatus($this->status),
            'product_note'            => null,
            'attribute_type'          => null,
            'buyer_status'            => $this->paymentStatus,

            // Sub IDs & Channel
            'sub_id1'                 => $this->subId,
            'sub_id2'                 => $this->sub1,
            'sub_id3'                 => $this->sub2,
            'sub_id4'                 => $this->sub3,
            'sub_id5'                 => $this->sub4,
            'sub_id5'                 => null,
            'channel'                 => $this->contentType,

            // System fields
            'platform'                => 'TikTok',
            'user_id'                 => $userId,
            'username'                => $username,

            // Cashback (calculated later by business logic)
            'cashback_rate'           => null,
            'cashback_amount'         => null,

            // Import tracking
            'import_batch'            => $importBatch,
            'source_file'             => 'rioHub-api',
            'first_imported_at'       => now()->toDateTimeString(),
            'last_tiktok_sync_at'     => now()->toDateTimeString(),
            'locked_at'               => in_array($this->status, [2, 3]) ? now()->toDateTimeString() : null,
        ];
    }

    /**
     * Map TikTok numeric status to Vietnamese affiliate_status.
     *
     *  1 = Pending / awaiting settlement
     *  2 = Settled / completed
     *  3 = Cancelled / refunded
     */
    private function mapStatus(?int $status): string
    {
        return match ($status) {
            2      => 'Hoàn thành',
            3      => 'Đã hủy',
            default => 'Đang xử lý',
        };
    }
}
