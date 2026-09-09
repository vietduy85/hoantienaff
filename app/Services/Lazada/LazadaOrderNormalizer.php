<?php

namespace App\Services\Lazada;

use App\Services\Lazada\DTOs\LazadaConversionRecord;
use Illuminate\Support\Carbon;

/**
 * Produces the database-ready row for one Lazada conversion line.
 *
 * The Lazada conversion report carries no unit price and no quantity, so the
 * two NOT NULL pricing columns on the shared Shopee-schema table get explicit
 * placeholders (item_price = orderAmt, quantity = 1) — documented so nobody
 * mistakes them for real item-level pricing. Commission and cashback NEVER
 * read these placeholders: they use the REAL payout (estPayout / basePayout /
 * bonusPayout) and orderAmt.
 *
 * item_id is always NULL for Lazada rows: two sub-order lines may share a
 * numeric sku, so populating item_id would collide with the protected
 * uk_platform_order_item index (same policy as ShopeeFood).
 */
class LazadaOrderNormalizer
{
    public const PLATFORM = 'Lazada';

    public function __construct(
        private readonly LazadaUserResolver $resolver,
        private readonly LazadaCashbackCalculator $cashback,
    ) {}

    /**
     * @param  array{user_id: int|null, username: string|null, matched_by: string}  $resolved
     *
     * @return array<string, mixed>
     */
    public function normalize(
        LazadaConversionRecord $record,
        array $resolved,
        string $status,
        string $importBatch,
        string $lineKey,
    ): array {
        $isCancelled = $status === LazadaOrderStatusMapper::STATUS_CANCELLED;

        $estPayout    = $record->getEstPayout();
        $orderAmt     = $record->getOrderAmt();
        $cashback     = $this->cashback->calculate($estPayout, $orderAmt, $isCancelled);

        $now = Carbon::now()->toDateTimeString();

        $terminal = LazadaOrderStatusMapper::isTerminal($status);

        $sellerIdRaw = $record->getSellerId();

        return [
            // Order & identity
            'order_id'               => $record->getOrderId(),
            'order_status'           => $status,
            'checkout_id'            => $record->getOrderId(),
            'ordered_at'             => $record->getConversionTime(),
            'completed_at'           => $record->getFulfilledTime() ?? $record->getDeliveredTime(),

            // Shop / seller
            'shop_name'              => $record->getSellerName() !== '' ? $record->getSellerName() : '?',
            'shop_id'                => ($sellerIdRaw !== '' && ctype_digit($sellerIdRaw)) ? (int) $sellerIdRaw : 0,

            // Item (sku from the KEY column; item_id always null — see class doc)
            'item_id'                => null,
            'item_name'              => $record->getSkuName() !== '' ? $record->getSkuName() : '?',
            'model_id'               => 0,
            'item_price'             => $orderAmt, // PLACEHOLDER — API has no unit price
            'quantity'               => 1,        // PLACEHOLDER — API has no quantity
            'order_amount'           => $orderAmt,
            'refund_amount'          => 0,

            // Commission: REAL payout straight from the API — never re-computed
            'commission_type'        => $record->getCommissionType() !== '' ? $record->getCommissionType() : $record->getOfferType(),
            'campaign_partner'       => null,
            'shopee_commission_rate' => $record->getBaseCommissionRate(),
            'shopee_commission'      => $record->getBasePayout(),
            'seller_commission_rate' => $record->getBonusCommissionRate(),
            'xtra_commission'        => $record->getBonusPayout(),
            'total_product_commission' => $estPayout,
            'order_commission_shopee'  => $estPayout,
            'order_commission_seller'  => 0,
            'total_order_commission'   => $estPayout,
            'agreed_commission_rate'   => $record->getCommissionRate(),
            'net_commission'           => $estPayout,

            // Status + tracking tokens
            'affiliate_status'       => $status,
            'sub_id1'                => $record->getSubId1(),
            'sub_id2'                => $record->getSubId2(),
            'sub_id3'                => $record->getSubId3(),
            'sub_id4'                => $record->getSubId4(),
            'sub_id5'                => $record->getSubId5(),

            // System
            'platform'               => self::PLATFORM,
            'user_id'                => $resolved['user_id'],
            'username'               => $resolved['username'],
            'cashback_rate'          => $cashback['cashback_rate'],
            'cashback_amount'        => $cashback['cashback_amount'],
            'import_batch'           => $importBatch,
            'source_file'            => 'lazada-api',
            'first_imported_at'      => $now,
            'last_lazada_sync_at'    => $now,
            'lazada_line_key'        => $lineKey,
            'locked_at'              => $terminal ? $now : null,
        ];
    }
}