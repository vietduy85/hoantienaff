<?php

namespace App\Services\AccessTrade;

use App\Services\CashbackCalculator;

/**
 * Estimated cashback for an AccessTrade product link.
 *
 * AccessTrade does NOT expose a per-product commission through any link-creation
 * or product endpoint. The only commission figures the API returns are
 * campaign-level:
 *
 *  - `/v1/campaigns` `max_com` — the campaign's advertised maximum rate (a
 *    string such as `6%`, `10,5%`, `0.63% / giá trị đơn hàng`, or a fixed
 *    amount / several rates).
 *  - `/v1/campaigns?campaign_id=` `description.commission_policy` — the
 *    human-readable policy (HTML) that accompanies the rate.
 *
 * A rate is only used when the two AGREE: `max_com` must parse as exactly ONE
 * percentage and the commission policy must mention that same percentage as its
 * only commission rate. Category tables (MediaMart, CellphoneS, Lug, Hoàng Hà),
 * multi-rate or fixed-amount policies (Trip.com, VIB, VPBank) and policies whose
 * rate CONTRADICTS `max_com` (Con Cưng, BỀN Computer) are rejected — the user
 * then sees "Chưa tính được tiền hoàn dự kiến" instead of a guessed number.
 *
 * The publisher commission is fed into the project's ONLY cashback formula,
 * {@see CashbackCalculator} — no new formula and no new tax rate is introduced.
 * AccessTrade policies state the rate is "chưa bao gồm VAT" (before tax) for
 * almost every campaign, so the existing after-tax step inside the calculator is
 * applied unchanged.
 */
class AccessTradeCommissionEstimateService
{
    public function __construct(
        private readonly CashbackCalculator $cashback,
    ) {}

    /**
     * Estimate the user cashback for a product.
     *
     * @return array{commission: float, cashback_rate: float, user_estimated_cashback: int}|null
     *                                                                                           null when the campaign rate cannot be derived with confidence.
     */
    public function estimate(int|float $productPrice, ?string $maxCommission, ?string $commissionPolicy): ?array
    {
        $rate = $this->deriveRate($maxCommission, $commissionPolicy);

        if ($rate === null) {
            return null;
        }

        $price = (float) $productPrice;

        if ($price <= 0) {
            return null;
        }

        $commission = round($price * $rate, 2);

        if ($commission <= 0) {
            return null;
        }

        $cashback = $this->cashback->calculate($commission, $price);

        return [
            'commission' => $commission,
            'cashback_rate' => (float) $cashback['cashback_rate'],
            'user_estimated_cashback' => (int) $cashback['user_estimated_cashback'],
        ];
    }

    /**
     * Derive a confirmed campaign commission rate (as a fraction, e.g. 0.06 for
     * 6%), or null when `max_com` and the campaign policy do not unambiguously
     * agree on a single percentage.
     */
    public function deriveRate(?string $maxCommission, ?string $commissionPolicy): ?float
    {
        $maxPercent = $this->parseSinglePercentage($maxCommission);

        if ($maxPercent === null) {
            return null;
        }

        $policyPercent = $this->policyPercentages($commissionPolicy);

        if ($policyPercent === []) {
            return null;
        }

        foreach ($policyPercent as $percent) {
            if (abs($percent - $maxPercent) > 0.0001) {
                return null;
            }
        }

        return round($maxPercent / 100, 6);
    }

    /**
     * Parse `max_com` into a single percentage number (e.g. 6.0), or null.
     *
     * Accepts only one percentage, optionally followed by a qualifier such as
     * " / giá trị đơn hàng". Multiple percentages, fixed amounts and free text
     * are rejected.
     */
    private function parseSinglePercentage(?string $value): ?float
    {
        $value = $this->plain($value);

        if ($value === null) {
            return null;
        }

        if (substr_count($value, '%') !== 1) {
            return null;
        }

        if (! preg_match('/^(\d+(?:[.,]\d+)?)\s*%(?:\s*\/\s*.*)?$/u', $value, $m)) {
            return null;
        }

        $percent = (float) str_replace(',', '.', $m[1]);

        return ($percent > 0 && $percent < 100) ? $percent : null;
    }

    /**
     * All distinct commission percentages mentioned in a commission policy.
     *
     * VAT/GTGT percentages (e.g. "8% VAT") are removed first because they are a
     * tax figure, not a commission rate. An empty list means "cannot
     * corroborate".
     *
     * @return array<int, float>
     */
    private function policyPercentages(?string $policy): array
    {
        $text = $this->plain($policy);

        if ($text === null) {
            return [];
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\d+(?:[.,]\d+)?\s*%\s*(?:\+|và|va)?\s*(?:vat|gtgt)/u', ' ', $text) ?? $text;

        if (! preg_match_all('/(\d+(?:[.,]\d+)?)\s*%/u', $text, $m)) {
            return [];
        }

        $out = [];

        foreach ($m[1] as $raw) {
            $out[] = (float) str_replace(',', '.', $raw);
        }

        return array_values(array_unique($out));
    }

    private function plain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\u{FEFF}", '', trim($value));

        return $value === '' ? null : $value;
    }
}
