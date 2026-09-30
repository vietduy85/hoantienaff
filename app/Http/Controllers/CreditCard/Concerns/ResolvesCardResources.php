<?php

namespace App\Http\Controllers\CreditCard\Concerns;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\UserCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Tra cứu resource Credit Card theo đúng thẻ mà request đang nói tới.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN TRA TILE
 * ---------------------------------------------------------------------------
 * Ba điều kiện lặp lại ở mọi controller Phase 1C:
 *
 * 1. Thẻ phải thuộc user (tra cứu KHÔNG lọc owner, để Policy trả 403 thay vì
 *    service ném 422 — phân biệt "không có" với "không thuộc bạn" cũng là một
 *    dạng dò id).
 * 2. Policy version phải THUỘC THẺ đó (`Policy::forCard()`), không phải chỉ "user
 *    được phép xem". Nhờ vậy IDOR bị chặn ở tầng truy vấn chứ không chỉ ở Policy.
 * 3. Version của THẺ khác trả 404, không trả 403 — `forCard()` lọc theo
 *    `user_card_id` nên bản ghi không thuộc thẻ coi như không tồn tại.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CHẶN BLUEPRINT
 * ---------------------------------------------------------------------------
 * `Policy::isBlueprint()` = `user_card_id IS NULL` ⇒ đó là bản mẫu của TEMPLATE,
 * không phải cấu hình của một thẻ. `PolicyPolicy::view()` cố ý cho phép đọc
 * blueprint qua đường template; nếu để lọt vào route version/tier/rule thì user
 * đọc được cấu hình thô của template riêng của người khác. Ở đây trả 404.
 */
trait ResolvesCardResources
{
    /**
     * Thẻ trong request, đã authorize.
     */
    private function cardFor(Request $request, string $userCard): UserCard
    {
        $card = UserCard::findOrFail((int) $userCard);

        $this->authorize('view', $card);

        return $card;
    }

    /**
     * Policy version thuộc đúng thẻ này.
     */
    private function versionOfCard(UserCard $card, string $policy): Policy
    {
        $version = Policy::query()
            ->forCard($card->id)
            ->whereKey((int) $policy)
            ->first();

        abort_if($version === null, 404, 'Không tìm thấy phiên bản chính sách của thẻ này.');

        return $version;
    }

    /**
     * Version dùng làm "policy cha" ở các route phẳng (`/chinh-sach/{policy}/bac`).
     *
     * Blueprint không phải cấu hình của thẻ ⇒ 404.
     */
    private function versionAsParent(string $policy): Policy
    {
        $version = Policy::query()->whereKey((int) $policy)->firstOrFail();

        $this->assertNotBlueprint($version);

        $this->authorize('view', $version);

        return $version;
    }

    /**
     * Bậc làm "tier cha" ở route phẳng rule.
     */
    private function tierAsParent(string $tier): PolicyTier
    {
        $model = PolicyTier::query()->whereKey((int) $tier)->firstOrFail();

        $this->authorize('view', $model);

        return $model;
    }

    private function assertNotBlueprint(Policy $version): void
    {
        abort_if($version->isBlueprint(), 404, 'Không tìm thấy phiên bản chính sách.');
    }

    /**
     * Rule/tier đã tra cứu, chỉ dùng để authorize rồi chuyển id cho service.
     */
    private function idOf(Model $model): int
    {
        return (int) $model->getKey();
    }
}
