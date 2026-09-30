<?php

namespace App\Policies\CreditCard\Concerns;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\UserCard;

/**
 * Tra cứu thẻ sở hữu của một đối tượng con trong cây Policy.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN
 * ---------------------------------------------------------------------------
 * `PolicyTier` và `PolicyTierCategory` không có `user_id`: quyền sở hữu của chúng
 * đi xa qua chuỗi
 *
 *     PolicyTier      → policyVersion (PolicyVersion) → userCard → user_id
 *     PolicyTierCategory → tier (PolicyTier)           → …
 *
 * Viết chuỗi này ở từng policy thì dễ sót một nhánh ⇒ một loại dữ liệu lọt ra ngoài.
 * Gom vào một chỗ để mọi policy dùng chung, và đổi quan hệ sau này chỉ sửa một file.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO TRẢ VỀ `null` KHI THIẾU QUAN HỆ
 * ---------------------------------------------------------------------------
 * Bản ghi mồ côi (không còn thẻ, không còn version) là dữ liệu hỏng. Trả `null` để
 * policy từ chối quyền — ngược lại `?->` sẽ biến "hỏng dữ liệu" thành "cho qua".
 */
trait ResolvesOwningCard
{
    /**
     * Thẻ sở hữu bậc chi tiêu, hoặc null nếu không resolve được.
     */
    protected function owningCardOfTier(PolicyTier $tier): ?UserCard
    {
        $version = $tier->policyVersion;

        if ($version === null) {
            return null;
        }

        return $this->cardOfVersion($version);
    }

    /**
     * Thẻ sở hữu quy tắc cashback, hoặc null nếu không resolve được.
     */
    protected function owningCardOfRule(PolicyTierCategory $rule): ?UserCard
    {
        $tier = $rule->tier;

        if ($tier === null) {
            return null;
        }

        return $this->owningCardOfTier($tier);
    }

    /**
     * Thẻ sở hữu một policy version.
     *
     * Policy và PolicyVersion dùng CHUNG bảng `credit_card_policies` nên đều có
     * `user_card_id` và cùng quan hệ `userCard()`.
     */
    protected function cardOfVersion(Policy $version): ?UserCard
    {
        // Template blueprint có `user_card_id = NULL` ⇒ không thuộc user nào.
        return $version->userCard;
    }

    /**
     * `user_id` chủ sở hữu thực sự, hoặc null nếu không resolve được chuỗi.
     */
    protected function ownerIdOfCard(?UserCard $card): ?int
    {
        return $card === null ? null : (int) $card->user_id;
    }
}
