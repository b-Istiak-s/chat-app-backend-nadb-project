<?php

use App\Console\Commands\PollPendingBdappsSubscriptionsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reconcile pending BDApps subscriptions every minute. Only touches
// rows whose local `status` is still `pending` — registered and
// unregistered rows are skipped.
Schedule::command(PollPendingBdappsSubscriptionsCommand::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();
