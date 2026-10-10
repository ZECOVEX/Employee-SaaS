<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §73-E: resolve pending overtime that outlived the policy window.
Schedule::command('overtime:expire-pending')->dailyAt('00:10');

// §15: sweep configured risk rules (weekly late-risk, [OT] overwork).
Schedule::command('risk:evaluate')->dailyAt('00:20');

// §17: finalize last month's status snapshots (after the 00:10 sweep).
Schedule::command('status:generate')->monthlyOn(1, '00:30');
