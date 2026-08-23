<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requiere que algo dispare "schedule:run" cada minuto (cron en Linux/VPS,
// Task Scheduler en Windows) — ver DEPLOY.md para la configuración de cada uno.
Schedule::command('backup:database')
    ->dailyAt('03:00')
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup.log'));
