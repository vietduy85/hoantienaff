<?php

namespace App\Services\Lazada;

use App\Models\User;
use App\Services\Lazada\DTOs\LazadaConversionRecord;

/**
 * Resolves which user a Lazada conversion belongs to, from the subId tokens.
 *
 * Closed business rule (from the Phase 1 link generation — Lazada appends the
 * sub_id tokens we embed in the promotion URL):
 *   subId1 IS the numeric users.id   -> match users.id
 *       + if subId2 is present it MUST equal users.username (cross-check)
 *   anything else / no match         -> UNRESOLVED (user_id = null)
 *
 * There is deliberately NO fallback account (same policy as ShopeeFood).
 * Lines with unresolved users are still persisted/reported, but never credited.
 */
class LazadaUserResolver
{
    /**
     * @return array{0: int|null, 1: string|null}  [user_id, username]
     */
    public function resolve(LazadaConversionRecord $record): array
    {
        $resolved = $this->resolveWithDetail($record);

        return [$resolved['user_id'], $resolved['username']];
    }

    /**
     * matched_by values:
     *   - sub_id1            resolved via numeric users.id in subId1
     *   - sub_id1_only       resolved via subId1 with no subId2 to cross-check
     *   - sub_id1_unknown    subId1 numeric but no such user
     *   - subid2_mismatch    subId1 matched but subId2 != username
     *   - sub_id1_missing    subId1 empty / non-numeric
     *   - no_subid           neither subId1 nor subId2 present
     *
     * @return array{user_id: int|null, username: string|null, matched_by: string}
     */
    public function resolveWithDetail(LazadaConversionRecord $record): array
    {
        $subId1 = trim($record->getSubId1());
        $subId2 = trim($record->getSubId2());

        if ($subId1 === '' && $subId2 === '') {
            return $this->unresolved('no_subid');
        }

        if ($subId1 === '' || ! ctype_digit($subId1)) {
            return $this->unresolved('sub_id1_missing');
        }

        $user = User::find((int) $subId1);

        if ($user === null) {
            return $this->unresolved('sub_id1_unknown');
        }

        if ($subId2 !== '' && $user->username !== $subId2) {
            return $this->unresolved('subid2_mismatch');
        }

        return [
            'user_id'    => $user->id,
            'username'   => $user->username,
            'matched_by' => $subId2 !== '' ? 'sub_id1' : 'sub_id1_only',
        ];
    }

    /**
     * @return array{user_id: null, username: null, matched_by: string}
     */
    private function unresolved(string $matchedBy): array
    {
        return [
            'user_id'    => null,
            'username'   => null,
            'matched_by' => $matchedBy,
        ];
    }
}