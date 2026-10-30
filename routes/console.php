<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('affiliate:cache-cleanup')->dailyAt('03:00')->timezone('Asia/Ho_Chi_Minh');

Schedule::command('logs:prune --days=7')->dailyAt('03:30')->timezone('Asia/Ho_Chi_Minh');
