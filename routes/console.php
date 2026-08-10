<?php

use App\Support\Operations\ProcessHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => ProcessHeartbeat::recordScheduler())
    ->name('operations:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('uploads:expire-inactive')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('uploads:recover-processing')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=720')
    ->daily()
    ->withoutOverlapping();
