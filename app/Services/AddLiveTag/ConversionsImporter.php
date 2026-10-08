<?php

namespace App\Services\AddLiveTag;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * PHASE 1: compare AddLiveTag-normalized rows against affiliate_order_items
 * and (optionally) apply SAFE row updates.
 *
 * Hard rules (Phase 1):
 *  - never creates/updates WalletTransactions
 *  - never calls WalletService
 *  - never touches finalized_at / locked_at / reversed_at / final_cashback_amount
 *  - never rewrites rows that already have a completed cashback credit
 *  - never overwrites an existing user mapping on conflict (report only)
 *  - never downgrades a completed row when API still reports paid/unpaid
 */
class ConversionsImporter
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_PROTECTED = 'protected';

    public const ACTION_UNCHANGED = 'unchanged';

    public const ACTION_NO_DOWNGRADE = 'no_downgrade';

    public const ACTION_USER_CONFLICT = 'user_mapping_conflict';

    public const SOURCE_FILE = 'addlivetag-api';

    /** Existing DB rows whose affiliate_status counts as completed. */
    private const COMPLETED_STATUS = 'Hoàn thành';

    /**
     * @param  array  $normalizedRows  flat normalized rows from ConversionsNormalizer
     * @return array{
     *   plan: list<array>,
     *   overlap_groups: int,
     *   overlap_orders: list<string>,
     *   counts: array<string,int>,
     *   user_conflicts: list<array>,
     *   sub_id_mismatches: list<array>,
     *   mcn_fee_rows: int
     * }
     */
    public function plan(array $normalizedRows, string $importBatch): array
    {
        $groups = [];
        foreach ($normalizedRows as $i => $row) {
            $key = $row['order_id'] . '|' . $row['item_id'];
            $groups[$key]['rows'][] = $row;
            $groups[$key]['indexes'][] = $i;
        }

        $overlapGroups = [];
        foreach ($groups as $key => $group) {
            if (count($group['rows']) > 1) {
                $overlapGroups[$key] = true;
            }
        }

        $overlapOrders = array_values(array_unique(array_map(
            static fn (string $k): string => explode('|', $k, 2)[0],
            array_keys($overlapGroups)
        )));
        sort($overlapOrders);

        $existing = $this->loadExisting(array_keys($groups));
        $creditedIds = $this->loadCreditedIds($existing);

        $plan = [];
        $counts = [
            self::ACTION_CREATE => 0,
            self::ACTION_UPDATE => 0,
            self::ACTION_PROTECTED => 0,
            self::ACTION_UNCHANGED => 0,
            self::ACTION_NO_DOWNGRADE => 0,
            self::ACTION_USER_CONFLICT => 0,
        ];
        $userConflicts = [];
        $subIdMismatches = [];
        $mcnFeeRows = 0;

        foreach ($groups as $key => $group) {
            [$orderSn, $itemId] = explode('|', $key, 2);

            // Deterministic LAST-WINS / final-line preference (same as CSV import).
            $row = end($group['rows']);
            $rowIndex = end($group['indexes']);

            if ((float) ($row['mcn_management_fee'] ?? 0) > 0) {
                $mcnFeeRows++;
            }

            $row['import_batch'] = $importBatch;
            $row['source_file'] = self::SOURCE_FILE;
            $row['line_index'] = $rowIndex;

            $dbRow = $existing[$key] ?? null;

            if ($dbRow === null) {
                $plan[] = $this->entry(self::ACTION_CREATE, $orderSn, $itemId, $row, null, null, $group);
                $counts[self::ACTION_CREATE]++;

                continue;
            }

            $resolvedUserId = $this->resolveUserId($row['sub_id1'] ?? null);
            $userConflict = false;
            if ($dbRow->username !== null
                && ($row['sub_id1'] ?? null) !== null
                && $dbRow->username !== $row['sub_id1']
                && $resolvedUserId !== null
                && $dbRow->user_id !== null
                && $resolvedUserId !== $dbRow->user_id
            ) {
                $userConflict = true;
            }

            if ($userConflict) {
                $plan[] = $this->entry(self::ACTION_USER_CONFLICT, $orderSn, $itemId, $row, $dbRow, null, $group);
                $counts[self::ACTION_USER_CONFLICT]++;
                $userConflicts[] = [
                    'order_id' => $orderSn,
                    'item_id' => $itemId,
                    'db_username' => $dbRow->username,
                    'api_sub_id1' => $row['sub_id1'],
                    'db_user_id' => $dbRow->user_id,
                    'api_user_id' => $resolvedUserId,
                ];

                continue;
            }

            // Info-only: API sub_id1 differs from DB username but does NOT
            // qualify as a conflict (API sub_id1 resolves to nobody, or to the
            // same user). Never blocks, never rewrites the user mapping.
            if ($dbRow->username !== null
                && ($row['sub_id1'] ?? null) !== null
                && $dbRow->username !== $row['sub_id1']
            ) {
                $subIdMismatches[$orderSn . '|' . $itemId] = [
                    'order_id' => $orderSn,
                    'item_id' => $itemId,
                    'db_username' => $dbRow->username,
                    'api_sub_id1' => $row['sub_id1'],
                    'db_user_id' => $dbRow->user_id,
                    'api_user_id' => $resolvedUserId,
                ];
            }

            $protected = $dbRow->isFinalized()
                || $dbRow->locked_at !== null
                || $dbRow->isReversed()
                || isset($creditedIds[$dbRow->id]);

            $changes = $this->diff($dbRow, $row);

            if ($protected) {
                if ($changes === []) {
                    $plan[] = $this->entry(self::ACTION_UNCHANGED, $orderSn, $itemId, $row, $dbRow, null, $group);
                    $counts[self::ACTION_UNCHANGED]++;
                } else {
                    $plan[] = $this->entry(self::ACTION_PROTECTED, $orderSn, $itemId, $row, $dbRow, $changes, $group);
                    $counts[self::ACTION_PROTECTED]++;
                }

                continue;
            }

            // Spec §11: never downgrade a completed row when API says paid/unpaid.
            if ($dbRow->affiliate_status === self::COMPLETED_STATUS
                && $row['affiliate_status'] !== self::COMPLETED_STATUS
                && $changes !== []
            ) {
                $plan[] = $this->entry(self::ACTION_NO_DOWNGRADE, $orderSn, $itemId, $row, $dbRow, $changes, $group);
                $counts[self::ACTION_NO_DOWNGRADE]++;

                continue;
            }

            if ($changes === []) {
                $plan[] = $this->entry(self::ACTION_UNCHANGED, $orderSn, $itemId, $row, $dbRow, null, $group);
                $counts[self::ACTION_UNCHANGED]++;

                continue;
            }

            $plan[] = $this->entry(self::ACTION_UPDATE, $orderSn, $itemId, $row, $dbRow, $changes, $group);
            $counts[self::ACTION_UPDATE]++;
        }

        return [
            'plan' => $plan,
            'overlap_groups' => count($overlapGroups),
            'overlap_orders' => $overlapOrders,
            'counts' => $counts,
            'user_conflicts' => $userConflicts,
            'sub_id_mismatches' => array_values($subIdMismatches),
            'mcn_fee_rows' => $mcnFeeRows,
        ];
    }

    /**
     * Apply only create/update actions. NEVER touches the wallet.
     *
     * @return array{created:int, updated:int}
     */
    public function apply(array $planResult): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($planResult) {
            $created = 0;
            $updated = 0;

            $now = Carbon::now();

            foreach ($planResult['plan'] as $entry) {
                if ($entry['action'] === self::ACTION_CREATE) {
                    $row = $entry['row'];

                    AffiliateOrderItem::create([
                        'order_id' => $entry['order_id'],
                        'order_status' => $row['order_status'],
                        'checkout_id' => $row['checkout_id'] ?? '',
                        'ordered_at' => $row['ordered_at'] !== null ? Carbon::parse($row['ordered_at']) : null,
                        'completed_at' => $row['completed_at'] !== null ? Carbon::parse($row['completed_at']) : null,
                        'clicked_at' => $row['clicked_at'] !== null ? Carbon::parse($row['clicked_at']) : null,
                        // Shop/model columns are NOT NULL but the AddLiveTag
                        // conversions payload carries no shop data — mirror the
                        // TikTok sync convention (empty/0 defaults).
                        'shop_name' => '',
                        'shop_id' => 0,
                        'item_id' => $entry['item_id'],
                        'item_name' => $row['item_name'] ?? '',
                        'model_id' => 0,
                        'item_price' => $row['item_price'],
                        'quantity' => $row['quantity'],
                        'order_amount' => $row['order_amount'],
                        'refund_amount' => 0,
                        'commission_type' => '',
                        'shopee_commission_rate' => 0,
                        'shopee_commission' => 0,
                        'seller_commission_rate' => 0,
                        'xtra_commission' => 0,
                        'total_product_commission' => $row['total_product_commission'],
                        'order_commission_shopee' => 0,
                        'order_commission_seller' => 0,
                        'total_order_commission' => $row['total_product_commission'],
                        'mcn_management_fee' => $row['mcn_management_fee'],
                        'agreed_commission_rate' => 0,
                        'net_commission' => $row['total_product_commission'],
                        'affiliate_status' => $row['affiliate_status'],
                        'sub_id1' => $row['sub_id1'],
                        'sub_id2' => $row['sub_id2'],
                        'sub_id3' => $row['sub_id3'],
                        'sub_id4' => $row['sub_id4'],
                        'sub_id5' => $row['sub_id5'],
                        'platform' => 'Shopee',
                        'user_id' => $this->resolveUserId($row['sub_id1'] ?? null),
                        'username' => $row['sub_id1'],
                        'cashback_rate' => $row['cashback_rate'],
                        'cashback_amount' => $row['cashback_amount'],
                        'import_batch' => $row['import_batch'],
                        'source_file' => $row['source_file'],
                        'first_imported_at' => $now,
                        'last_shopee_sync_at' => $now,
                    ]);
                    $created++;

                    continue;
                }

                if ($entry['action'] !== self::ACTION_UPDATE) {
                    continue;
                }

                $dbRow = $entry['db_row'];
                $row = $entry['row'];
                $changes = $entry['changes'];

                $payload = ['last_shopee_sync_at' => $now];
                foreach (array_keys($changes) as $field) {
                    $payload[$field] = $row[$field];
                }
                // Refresh batch/source markers on every touch.
                $payload['import_batch'] = $row['import_batch'];
                $payload['source_file'] = $row['source_file'];

                // Re-read to re-apply lifecycle guards at write time.
                $fresh = AffiliateOrderItem::query()
                    ->where('id', $dbRow->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null
                    || $fresh->isFinalized()
                    || $fresh->locked_at !== null
                    || $fresh->isReversed()
                    || $fresh->hasCompletedCashbackCredit()
                ) {
                    continue;
                }

                if ($fresh->affiliate_status === self::COMPLETED_STATUS
                    && ($payload['affiliate_status'] ?? $fresh->affiliate_status) !== self::COMPLETED_STATUS
                ) {
                    unset($payload['affiliate_status']);
                }

                $fresh->fill($payload);
                $fresh->save();
                $updated++;
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * @param  list<string>  $keys  "orderSn|itemId"
     * @return array<string, AffiliateOrderItem>
     */
    private function loadExisting(array $keys): array
    {
        $orderIds = [];
        foreach ($keys as $key) {
            $orderIds[] = explode('|', $key, 2)[0];
        }
        $orderIds = array_values(array_unique($orderIds));

        $map = [];
        AffiliateOrderItem::query()
            ->where('platform', 'Shopee')
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get()
            ->each(function (AffiliateOrderItem $row) use (&$map) {
                $map[$row->order_id . '|' . $row->item_id] = $row;
            });

        return $map;
    }

    /**
     * Batch-load affiliate_order_item ids that already have a COMPLETED cashback
     * credit (money already moved) so plan() avoids one query per row.
     *
     * @param  array<string, AffiliateOrderItem>  $existing
     * @return array<int, true>
     */
    private function loadCreditedIds(array $existing): array
    {
        if ($existing === []) {
            return [];
        }

        $ids = array_map(static fn (AffiliateOrderItem $row): int => $row->id, array_values($existing));

        $credited = [];
        \App\Models\WalletTransaction::query()
            ->where('reference_type', 'affiliate_order_item')
            ->whereIn('reference_id', $ids)
            ->where('type', \App\Models\WalletTransaction::TYPE_CASHBACK)
            ->where('status', \App\Models\WalletTransaction::STATUS_COMPLETED)
            ->pluck('reference_id')
            ->each(function ($id) use (&$credited) {
                $credited[(int) $id] = true;
            });

        return $credited;
    }

    private function resolveUserId(?string $subId1): ?int
    {
        $subId1 = $subId1 !== null ? trim($subId1) : null;
        if ($subId1 === null || $subId1 === '') {
            return null;
        }

        return User::where('username', $subId1)->value('id');
    }

    /**
     * Fields the API sync is allowed to change on an ordinary row.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(AffiliateOrderItem $dbRow, array $row): array
    {
        $changes = [];

        $stringFields = ['affiliate_status', 'order_status', 'checkout_id', 'item_name'];
        foreach ($stringFields as $field) {
            $old = $dbRow->{$field};
            $new = $row[$field] ?? null;
            if ($new !== null && (string) $old !== (string) $new) {
                $changes[$field] = [$old, $new];
            }
        }

        $numericFields = ['item_price', 'quantity', 'order_amount', 'total_product_commission', 'cashback_rate', 'cashback_amount', 'mcn_management_fee'];
        foreach ($numericFields as $field) {
            $old = (float) ($dbRow->{$field} ?? 0);
            $new = (float) ($row[$field] ?? 0);
            if (abs($old - $new) > 0.009) {
                $changes[$field] = [$old, $new];
            }
        }

        $dateFields = ['ordered_at', 'completed_at', 'clicked_at'];
        foreach ($dateFields as $field) {
            $old = $dbRow->{$field} !== null ? $dbRow->{$field}->format('Y-m-d H:i:s') : null;
            $new = $row[$field] ?? null;
            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }

    private function entry(
        string $action,
        string $orderSn,
        string $itemId,
        array $row,
        ?AffiliateOrderItem $dbRow,
        ?array $changes,
        array $group
    ): array {
        return [
            'action' => $action,
            'order_id' => $orderSn,
            'item_id' => $itemId,
            'row' => $row,
            'db_row' => $dbRow,
            'changes' => $changes,
            'overlap_lines' => count($group['rows']),
        ];
    }
}
