<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tte:audit-verify --json')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('evidence:reconcile --json')->dailyAt('02:30')->withoutOverlapping();
