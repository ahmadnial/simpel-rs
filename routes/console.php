<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tte:audit-verify --json')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('evidence:reconcile --json')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('tte:process-signing-outbox --limit=25')->everyMinute()->withoutOverlapping(10);

// Submitted PDFs live under documents/, so pruning caches cannot remove reviewed/official files.
Artisan::command('documents:prune-previews', function () {
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    $cutoff = now()->subDays(7)->timestamp;
    $deleted = 0;
    foreach (['pdf_cache', 'temp_processed'] as $directory) {
        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < $cutoff && $disk->delete($file)) {
                $deleted++;
            }
        }
    }
    $this->info("{$deleted} berkas cache pratinjau dibersihkan.");
})->purpose('Hapus cache pratinjau yang berumur lebih dari tujuh hari');

Schedule::command('documents:prune-previews')->dailyAt('03:30')->withoutOverlapping();
