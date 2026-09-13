<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AffiliateOrderItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'Đang xử lý';

    public const STATUS_COMPLETED = 'Hoàn thành';

    public const STATUS_CANCELLED = 'Đã hủy';

    public const FINALIZE_GATE_TIKTOK_SETTLED = 'tiktok_settled';

    public const FINALIZE_GATE_LAZADA_DELIVERED_10D = 'lazada_delivered_10d';

    protected $fillable = [
        // Shopee fields
        'order_id',
        'order_status',
        'checkout_id',
        'content_id',
        'ordered_at',
        'completed_at',
        'clicked_at',
        'shop_name',
        'shop_id',
        'shop_type',
        'item_id',
        'item_name',
        'model_id',
        'product_type',
        'promotion_id',
        'category_l1',
        'category_l2',
        'category_l3',
        'item_price',
        'quantity',
        'order_amount',
        'refund_amount',
        'commission_type',
        'campaign_partner',
        'shopee_commission_rate',
        'shopee_commission',
        'seller_commission_rate',
        'xtra_commission',
        'total_product_commission',
        'order_commission_shopee',
        'order_commission_seller',
        'total_order_commission',
        'mcn_name',
        'mcn_contract_code',
        'mcn_management_fee_rate',
        'mcn_management_fee',
        'agreed_commission_rate',
        'net_commission',
        'affiliate_status',
        'product_note',
        'attribute_type',
        'buyer_status',
        'sub_id1',
        'sub_id2',
        'sub_id3',
        'sub_id4',
        'sub_id5',
        'channel',
        // System fields
        'platform',
        'user_id',
        'username',
        'cashback_rate',
        'cashback_amount',
        'import_batch',
        'source_file',
        'first_imported_at',
        'last_shopee_sync_at',
        'last_tiktok_sync_at',
        'last_shopeefood_sync_at',
        'shopee_food_line_key',
        'lazada_line_key',
        'last_lazada_sync_at',
        'lazada_raw_status',
        'locked_at',
        'delivered_at',
        'finalized_at',
        'finalize_gate',
        'final_cashback_amount',
        'reversed_at',
        'tt_order_status',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'completed_at' => 'datetime',
            'clicked_at' => 'datetime',
            'item_price' => 'decimal:2',
            'order_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'shopee_commission_rate' => 'decimal:2',
            'shopee_commission' => 'decimal:2',
            'seller_commission_rate' => 'decimal:2',
            'xtra_commission' => 'decimal:2',
            'total_product_commission' => 'decimal:2',
            'order_commission_shopee' => 'decimal:2',
            'order_commission_seller' => 'decimal:2',
            'total_order_commission' => 'decimal:2',
            'mcn_management_fee_rate' => 'decimal:2',
            'mcn_management_fee' => 'decimal:2',
            'agreed_commission_rate' => 'decimal:2',
            'net_commission' => 'decimal:2',
            'cashback_rate' => 'decimal:2',
            'cashback_amount' => 'decimal:2',
            'first_imported_at' => 'datetime',
            'last_shopee_sync_at' => 'datetime',
            'last_tiktok_sync_at' => 'datetime',
            'last_shopeefood_sync_at' => 'datetime',
            'lazada_line_key' => 'string',
            'last_lazada_sync_at' => 'datetime',
            'lazada_raw_status' => 'string',
            'locked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'finalized_at' => 'datetime',
            'final_cashback_amount' => 'decimal:2',
            'reversed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when this row has been finalized (LOCKED) by the lifecycle.
     *
     * Historical rows that were credited BEFORE the lifecycle keep this NULL —
     * their protection signal is `hasCompletedCashbackCredit()` (a completed
     * cashback WalletTransaction), not this flag.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /**
     * True when a valid reversal has been recorded for this row.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Frozen cashback amount captured at finalization time, or null when the
     * row has never been finalized.
     */
    public function getFinalCashbackAmount(): ?float
    {
        return $this->final_cashback_amount !== null
            ? (float) $this->final_cashback_amount
            : null;
    }

    /**
     * Mark the row as finalized (LOCKED) by the lifecycle.
     *
     * Captures a snapshot of cashback_amount into final_cashback_amount so the
     * amount actually credited can never be recomputed/drifted afterwards.
     * Idempotent: once finalized, a second call is a no-op and NEVER overwrites
     * the existing snapshot (no downgrade, no recalculation).
     */
    public function markFinalized(string $gate, ?Carbon $at = null): static
    {
        if ($this->isFinalized()) {
            return $this;
        }

        $this->finalize_gate = $gate;
        $this->finalized_at = $at ?? Carbon::now();
        $this->final_cashback_amount = (float) $this->cashback_amount;
        $this->reversed_at = null;
        $this->save();

        return $this;
    }

    /**
     * Record a valid platform reversal on a previously finalized row.
     */
    public function markReversed(?Carbon $at = null): static
    {
        $this->reversed_at = $at ?? Carbon::now();
        $this->save();

        return $this;
    }

    /**
     * Historical-credit protection marker: a completed cashback credit exists
     * on this order item regardless of lifecycle finalization fields.
     *
     * This is the AUTHORITATIVE "money already moved" signal — rows where it
     * returns true must NEVER be re-credited, recalculated or downgraded by the
     * lifecycle (Lazada 720/721/722 and all pre-deploy credits).
     */
    public function hasCompletedCashbackCredit(): bool
    {
        return WalletTransaction::query()
            ->where('reference_type', 'affiliate_order_item')
            ->where('reference_id', $this->id)
            ->where('type', WalletTransaction::TYPE_CASHBACK)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->exists();
    }
}
