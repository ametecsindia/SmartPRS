<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Field-force compliance: scan DRA/PCC expiry every morning and email a
| digest to each tenant's HR/Admin. Requires the scheduler to be running on
| the server (one cron entry, or `php artisan schedule:work` during dev).
| withoutOverlapping guards against a slow run colliding with the next tick.
*/
Schedule::command('compliance:scan')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Production hardening (rev 35):
|  - Nightly database + files backup (spatie/laravel-backup), preceded by a
|    cleanup that prunes old archives per config/backup.php retention.
|  - Daily error digest: emails ops a summary of error-level log entries.
| All require the scheduler to be running on the server (cron: schedule:run
| every minute — see the Cloud VPS deploy guide).
*/
Schedule::command('backup:clean')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('errors:digest')
    ->dailyAt('07:45')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Subscription expiry alerts (rev 75) — REPLACES the rev-51 auto-invoice job
| (billing:renew) now that tenants renew themselves via Administration → My
| Subscription. Sends email + WhatsApp reminders 15/7/3/1 days before expiry,
| on the day, and daily through the 7-day grace; marks tenants 'suspended'
| after grace (live lock-out is the EnsureSubscriptionActive middleware).
| billing:renew still exists for manual/legacy use: `php artisan billing:renew`.
*/
Schedule::command('billing:alerts')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Employee transfers (rev 77): apply approved, future-dated transfers on their
| effective date (branch moves + master↔subsidiary company moves). Runs early
| so the employee is in the right company/branch before the workday's
| attendance and payroll activity.
*/
Schedule::command('transfers:apply')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();
