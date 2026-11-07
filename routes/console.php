<?php

use App\Billing\EditLocks;
use App\Forms\FormLocks;
use App\Workflows\Workflows;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:clear-expired-locks', function () {
    $this->info('Released '.(EditLocks::clearExpired() + FormLocks::clearExpired()).' expired editing lock(s).');
})->purpose('Release editing locks owned by inactive or revoked sessions');

Schedule::command('billing:clear-expired-locks')->everyMinute()->withoutOverlapping();

Artisan::command('workflows:run-pending', function () {
    $this->info('Checked '.Workflows::runPending().' active workflow(s).');
})->purpose('Advance active workflows: timers, automatic retries and interrupted stages');

Schedule::command('workflows:run-pending')->everyMinute()->withoutOverlapping();
