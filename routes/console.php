<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=0')->daily();

// SITREP preparation at both cutoffs (D-24, D-44, D-147). The intended
// cutoff is passed explicitly so a delayed run still snapshots the window
// that closed; withoutOverlapping + onOneServer guard a slow or duplicated
// scheduler, and the command's prepared-run check makes retries idempotent.
$sitrepTimezone = (string) config('deadlines.timezone', 'Asia/Manila');

Schedule::command('sitrep:prepare-cutoff 04:00')
    ->dailyAt('04:00')
    ->timezone($sitrepTimezone)
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sitrep:prepare-cutoff 15:00')
    ->dailyAt('15:00')
    ->timezone($sitrepTimezone)
    ->withoutOverlapping()
    ->onOneServer();
