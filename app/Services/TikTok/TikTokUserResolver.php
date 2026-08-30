<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Services\TikTok\DTOs\TikTokOrder;

/**
 * Resolves which user an incoming TikTok order belongs to.
 *
 * Closed business rule (NOT to be extended without review):
 *   1. sub_id / sub1 empty            -> fallback account (tintuctonghop103).
 *   2. sub_id / sub1 present          -> try users.username match.
 *   3. sub_id / sub1 unresolved       -> fallback account (tintuctonghop103).
 *
 * The resolver NEVER returns a null user_id: an order that cannot be mapped
 * confidently is attributed to the designated fallback account.
 */
class TikTokUserResolver
{
    private ?array $fallbackUser = null;

    public function __construct(
        private readonly string $fallbackUsername = 'tintuctonghop103',
    ) {}

    /**
     * @return array{0: int, 1: string}  [user_id, username]
     */
    public function resolve(TikTokOrder $order): array
    {
        $resolved = $this->resolveWithDetail($order);

        return [$resolved['user_id'], $resolved['username']];
    }

    /**
     * Resolve a user and report which business-rule branch was taken.
     *
     * matched_by values:
     *   - sub_id             resolved via sub_id username match
     *   - sub1               resolved via sub1 username match
     *   - fallback_empty     both sub_id and sub1 empty -> fallback
     *   - fallback_not_found a sub was present but no user matched -> fallback
     *
     * @return array{user_id: int, username: string, matched_by: string}
     */
    public function resolveWithDetail(TikTokOrder $order): array
    {
        $subId = $order->getSubId();
        $sub1  = $order->getSub1();

        if (is_string($subId) && $subId !== '') {
            $user = User::where('username', $subId)->first();

            if ($user !== null) {
                return $this->matched($user, 'sub_id');
            }

            return $this->fallbackResult('fallback_not_found');
        }

        if (is_string($sub1) && $sub1 !== '') {
            $user = User::where('username', $sub1)->first();

            if ($user !== null) {
                return $this->matched($user, 'sub1');
            }

            return $this->fallbackResult('fallback_not_found');
        }

        return $this->fallbackResult('fallback_empty');
    }

    /**
     * @return array{user_id: int, username: string, matched_by: string}
     */
    private function matched(User $user, string $matchedBy): array
    {
        return [
            'user_id'    => $user->id,
            'username'   => $user->username,
            'matched_by' => $matchedBy,
        ];
    }

    /**
     * @return array{user_id: int, username: string, matched_by: string}
     */
    private function fallbackResult(string $matchedBy): array
    {
        $fallback = $this->fallbackUser();

        return [
            'user_id'    => $fallback['id'],
            'username'   => $fallback['username'],
            'matched_by' => $matchedBy,
        ];
    }

    /**
     * @return array{id: int, username: string}
     */
    private function fallbackUser(): array
    {
        if ($this->fallbackUser === null) {
            $user = User::where('username', $this->fallbackUsername)->first();

            $this->fallbackUser = $user !== null
                ? ['id' => $user->id, 'username' => $user->username]
                : ['id' => 0, 'username' => $this->fallbackUsername];
        }

        return $this->fallbackUser;
    }
}