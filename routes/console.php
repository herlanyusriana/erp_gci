<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Token APK berumur 30 hari. Tanpa pruning, setiap HP yang pernah login
// meninggalkan baris mati di personal_access_tokens dan tabel tumbuh terus.
// Jam 02:00 WIB (19:00 UTC) = di luar ketiga shift produksi (07:30-07:30),
// jadi tidak bersaing dengan laporan operator di pergantian shift.
Schedule::command('sanctum:prune-expired --hours=48')
    ->dailyAt('19:00')
    ->timezone('UTC')
    ->onOneServer();
