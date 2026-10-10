<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CreditCard\UserCard;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\TierResolverService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;

$periods = app(StatementPeriodService::class);
$resolver = app(TierResolverService::class);

$cards = UserCard::query()
    ->where('name', 'like', '%Stepup%')
    ->orWhere('name', 'like', '%MSB Online%')
    ->orWhere('name', 'like', '%MSB%Online%')
    ->get();

echo "=== CARDS ===\n";
foreach ($cards as $c) {
    echo "card#{$c->id} name={$c->name} user_id={$c->user_id} desired_spend={$c->desired_spend} policy={$c->current_policy_id} day={$c->statement_day} start={$c->statement_period_start} end={$c->statement_period_end}\n";
}

$today = CarbonImmutable::now();
echo "\ntoday={$today->toDateString()}\n";

foreach ($cards as $c) {
    echo "\n================ card#{$c->id} {$c->name} ================\n";
    [$s, $e] = $periods->currentBoundaries($c, $today);
    echo "boundaries: {$s->toDateString()} .. {$e->toDateString()}\n";

    $version = $c->currentPolicy;
    echo "currentPolicy: ".($version ? "id={$version->id} name={$version->name} status={$version->status}" : 'NULL')."\n";

    if ($version) {
        $tiers = PolicyTier::query()->where('policy_id', $version->id)->orderBy('sort_order')->get();
        echo "tiers (n=".count($tiers)."):\n";
        foreach ($tiers as $t) {
            echo "  tier#{$t->id} '{$t->name}' min={$t->min_total_spend} max={$t->max_total_spend} cap_period={$t->max_cashback_per_period}\n";
        }
        $tier = $resolver->resolveTierFromTiers($tiers, (float) $c->desired_spend);
        echo "TARGET tier (desired={$c->desired_spend}): ".($tier ? "#{$tier->id} {$tier->name}" : 'NULL')."\n";
        if ($tier) {
            echo "  TARGET: #{$tier->id} {$tier->name} cap_period={$tier->max_cashback_per_period}\n";
            foreach (PolicyTierCategory::query()->where('tier_id', $tier->id)->orderBy('sort_order')->get() as $r) {
                echo "    rule#{$r->id} cat={$r->category_id} combo={$r->combo_id} scope={$r->scope_type} pct={$r->cashback_percent} cap_cat={$r->max_cashback_per_category_per_period} frm={$r->spend_from} to={$r->spend_to} quota={$r->is_quota_category} enabled={$r->is_enabled}\n";
            }
        }
    }

    $txs = Transaction::query()
        ->where('user_card_id', $c->id)
        ->whereDate('transaction_date', '>=', $s->toDateString())
        ->whereDate('transaction_date', '<=', $e->toDateString())
        ->chronological()
        ->get();
    echo "txns in period: ".count($txs)."\n";
    $totSpend = '0'; $totCb = '0';
    foreach ($txs as $t) {
        echo "  tx#{$t->id} date={$t->transaction_date} amount={$t->amount} cat={$t->category_id} snapshot={$t->cashback_amount_snapshot} rule={$t->policy_tier_category_id}\n";
        $totSpend = bcadd($totSpend, (string) $t->amount, 2);
        $totCb = bcadd($totCb, (string) ($t->cashback_amount_snapshot ?? 0), 2);
    }
    echo "TOTAL spend={$totSpend} snapshot={$totCb}\n";

    if ($version) {
        $tiers = PolicyTier::query()->where('policy_id', $version->id)->get();
        $actualTier = $resolver->resolveTierFromTiers($tiers, (float) $totSpend);
        echo "ACTUAL tier (spend={$totSpend}): ".($actualTier ? "#{$actualTier->id} {$actualTier->name} cap={$actualTier->max_cashback_per_period}" : 'NULL')."\n";
        if ($actualTier) {
            foreach (PolicyTierCategory::query()->where('tier_id', $actualTier->id)->orderBy('sort_order')->get() as $r) {
                echo "  A rule#{$r->id} cat={$r->category_id} pct={$r->cashback_percent} cap_cat={$r->max_cashback_per_category_per_period} cap_tx={$r->max_cashback_per_transaction}\n";
            }
            echo "  A tx-caps: ".json_encode($resolver->transactionCapsForTier($actualTier))."\n";
        }
        $targetTier = $resolver->resolveTierFromTiers($tiers, (float) $c->desired_spend);
        if ($targetTier) {
            echo "  T tx-caps: ".json_encode($resolver->transactionCapsForTier($targetTier))."\n";
            $calc = app(\App\Services\CreditCard\CashbackCalculator::class);
            $txlines = [];
            foreach ($txs as $t) {
                $txlines[] = new \App\Services\CreditCard\TransactionLine(
                    id: (int) $t->id,
                    transactionDate: $t->transaction_date->toDateString(),
                    categoryId: $t->category_id === null ? null : (int) $t->category_id,
                    amount: (string) $t->amount,
                );
            }
            $results = $calc->calculate(
                rules: $resolver->rulesForTier($targetTier),
                transactions: $txlines,
                minTotalSpend: 0.0,
                maxCashbackPerPeriod: $targetTier->max_cashback_per_period === null ? null : (float) $targetTier->max_cashback_per_period,
                transactionCaps: $resolver->transactionCapsForTier($targetTier),
            );
            $sum = 0.0;
            foreach ($results as $res) {
                $sum += $res->cashbackAmountAsFloat();
            }
            echo "  CALC total at TARGET tier = {$sum}\n";
        }
    }

    $ov = app(CreditCardOverviewService::class)->forPage((int) $c->user_id);
    $row = $ov['cards'][$c->id] ?? null;
    if ($row) {
        echo "OVERVIEW: spent={$row['spent']} expected_cashback={$row['expected_cashback']} cashback={$row['cashback']} desired={$row['desired_spend']}\n";
        $q = $row['quota'] ?? null;
        if ($q) {
            echo "quota tier_id={$q['tier_id']} tier_name={$q['tier_name']} tier_used={$q['tier_cashback_used']} tier_max={$q['tier_cashback_max']} tier_remaining={$q['tier_cashback_remaining']}\n";
            foreach ($q['rules'] as $r) {
                echo "  Q rule#{$r['rule_id']} cat={$r['category_id']} '{$r['name']}' used={$r['cashback_used']} used_display={$r['cashback_used_display']} max={$r['cashback_max']} used_disp_avail={$r['cashback_available_for_rule']} scope_spend={$r['scope_spend']} exhausted=".var_export($r['is_exhausted'], true)."\n";
            }
        } else {
            echo "quota: NULL\n";
        }
    }
}
