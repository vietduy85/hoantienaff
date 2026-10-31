<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use Closure;
use Illuminate\Support\Collection;

/**
 * Nạp sẵn kỳ sao kê + dòng sao kê của NHIỀU thẻ trong 2 câu truy vấn cho các trang CHỈ ĐỌC
 * (Tổng quan, Sao kê). Trước đây mỗi thẻ tự truy vấn kỳ/sao kê của mình => N+1
 * (user 19 thẻ: 88-124 query/trang).
 *
 * Chỉ có hiệu lực BÊN TRONG `using()`; ra khỏi đó mọi lookup quay về truy vấn DB như cũ,
 * nên các đường GHI không bao giờ đọc phải dữ liệu nạp sẵn đã cũ.
 */
final class StatementReadCache
{
    /** @var array<int, Collection<int, StatementPeriod>>|null */
    private static ?array $periods = null;

    /** @var array<int, Collection<int|string, CreditCardStatement>>|null keyed by statement_period_id */
    private static ?array $statements = null;

    /**
     * @param  iterable<UserCard>  $cards
     */
    public static function using(iterable $cards, Closure $callback): mixed
    {
        $previous = [self::$periods, self::$statements];

        $ids = collect($cards)->map(fn (UserCard $card): int => (int) $card->id)->unique()->values()->all();
        $empty = array_fill_keys($ids, null);

        $periods = $ids === [] ? collect() : StatementPeriod::query()->whereIn('user_card_id', $ids)->orderBy('id')->get();
        $statements = $ids === [] ? collect() : CreditCardStatement::query()->whereIn('user_card_id', $ids)->orderBy('id')->get();

        $periodsById = $periods->keyBy('id');
        foreach ($statements as $statement) {
            if ($period = $periodsById->get($statement->statement_period_id)) {
                $statement->setRelation('statementPeriod', $period);
            }
        }

        self::$periods = array_map(fn () => collect(), $empty);
        foreach ($periods->groupBy('user_card_id') as $cardId => $group) {
            self::$periods[(int) $cardId] = $group->values();
        }

        self::$statements = array_map(fn () => collect(), $empty);
        foreach ($statements->groupBy('user_card_id') as $cardId => $group) {
            // Giữ bản ghi ĐẦU TIÊN mỗi kỳ, khớp `->first()` của truy vấn cũ.
            self::$statements[(int) $cardId] = $group->reverse()->keyBy('statement_period_id');
        }

        try {
            return $callback();
        } finally {
            [self::$periods, self::$statements] = $previous;
        }
    }

    /** @return Collection<int, StatementPeriod>|null null = không nạp sẵn cho thẻ này */
    public static function periodsFor(int $cardId): ?Collection
    {
        return self::$periods[$cardId] ?? null;
    }

    /** @return Collection<int|string, CreditCardStatement>|null */
    public static function statementsFor(int $cardId): ?Collection
    {
        return self::$statements[$cardId] ?? null;
    }

    /** Kỳ vừa tạo trong lúc đang nạp sẵn: thêm vào để lookup sau đó vẫn thấy. */
    public static function remember(StatementPeriod $period): void
    {
        $cardId = (int) $period->user_card_id;

        if (isset(self::$periods[$cardId])) {
            self::$periods[$cardId]->push($period);
        }
    }
}
