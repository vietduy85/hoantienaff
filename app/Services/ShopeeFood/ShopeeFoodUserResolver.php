<?php

namespace App\Services\ShopeeFood;

use App\Models\User;
use App\Services\ShopeeFood\DTOs\ShopeeFoodCheckout;

/**
 * Resolves which user a ShopeeFood checkout belongs to, from UTM data.
 *
 * Closed business rule (Phase 1 — NOT to be guessed):
 *   FORMAT A : sub_id1 IS the username              -> users.username match.
 *   FORMAT B : shareId is NOT a username; take the part before '-AppS-' /
 *              '-AccS-' (content_id) and try to reconcile against users.username.
 *   anything else / no match -> UNRESOLVED_USER (user_id = null).
 *
 * Unlike the TikTok resolver there is NO fallback account and this resolver
 * MAY return a null user_id. Lines with unresolved users are still reported
 * (and later imported), but no cashback is ever credited to them — the sync
 * service skips wallet credit when user_id is null.
 */
class ShopeeFoodUserResolver
{
    /**
     * @return array{0: int|null, 1: string|null}  [user_id, username]
     */
    public function resolve(ShopeeFoodCheckout $checkout): array
    {
        $resolved = $this->resolveWithDetail($checkout);

        return [$resolved['user_id'], $resolved['username']];
    }

    /**
     * matched_by values:
     *   - sub_id1            resolved via FORMAT A sub_id1 username match
     *   - content_id         resolved via FORMAT B content_id username reconcile
     *   - format_a_empty     FORMAT A with empty sub_id1
     *   - sub_id1_unknown    FORMAT A sub_id1 exists but no username match
     *   - format_b_empty     FORMAT B with empty content_id
     *   - content_id_unknown FORMAT B content_id exists but no username match
     *   - no_utm             no utm_content / unknown format
     *
     * @return array{user_id: int|null, username: string|null, matched_by: string}
     */
    public function resolveWithDetail(ShopeeFoodCheckout $checkout): array
    {
        if ($checkout->getUtmFormat() === 'A') {
            $username = $checkout->getSubId1();

            if ($username === '') {
                return $this->unresolved('format_a_empty');
            }

            return $this->matchExact($username, 'sub_id1');
        }

        if ($checkout->getUtmFormat() === 'B') {
            $contentId = $checkout->getContentId();

            if ($contentId === null || $contentId === '') {
                return $this->unresolved('format_b_empty');
            }

            // Best-effort reconcile only. A shareId is NOT a username and must
            // never be guessed or transformed into one.
            return $this->matchExact($contentId, 'content_id');
        }

        return $this->unresolved('no_utm');
    }

    /**
     * @return array{user_id: int, username: string, matched_by: string}
     */
    private function matchExact(string $username, string $matchedBy): array
    {
        $user = User::where('username', $username)->first();

        if ($user === null) {
            return $this->unresolved($matchedBy . '_unknown');
        }

        return [
            'user_id'    => $user->id,
            'username'   => $user->username,
            'matched_by' => $matchedBy,
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