<?php

namespace App\Services\CreditCard\Optimizer;

/**
 * OptimizationCriterion — tiêu chí tối ưu của "Tối ưu bộ thẻ hoàn tiền".
 *
 * Tiêu chí KHÔNG chỉ là thứ hạng hiển thị: nó còn quyết định cách SINH biến thể
 * phương án (thứ tự xếp hạng/heuristic của thể sinh) rồi mới cắt tối đa 3 phương
 * án distinct theo fingerprint.
 */
final class OptimizationCriterion
{
    public const MAX_CASHBACK = 'max_cashback';

    public const BALANCE = 'balance';

    public const FEWEST_CARDS = 'fewest_cards';

    public const MY_CARDS = 'my_cards';

    public const EASY_CONDITIONS = 'easy_conditions';

    public const NEW_CARDS = 'new_cards';

    /**
     * @return array<string, string> key => giải thích tiếng Việt ngắn
     */
    public static function labels(): array
    {
        return [
            self::BALANCE => 'Cân bằng: ưu tiên tổng hoàn tiền nhưng vẫn chú ý dàn đều',
            self::MAX_CASHBACK => 'Tối đa hoàn tiền, không quan tâm số thẻ',
            self::FEWEST_CARDS => 'Ít thẻ nhất mà vẫn đạt hoàn tiền tốt',
            self::MY_CARDS => 'Dùng thẻ của tôi trước, thẻ mới chỉ bổ sung phần thiếu',
            self::EASY_CONDITIONS => 'Ưu tiên thẻ dễ đạt (chi tiêu tối thiểu thấp)',
            self::NEW_CARDS => 'Ưa thẻ mới (hệ thống), dễ tách danh mục theo nhà phát hành',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}