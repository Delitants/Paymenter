<?php

use App\Classes\Settings;
use App\Console\Commands\CronJob;
use App\Console\Commands\FetchEmails;
use App\Console\Commands\ResellerClubSyncPrices;
use App\Console\Commands\ScheduleHeartbeatCommand;
use App\Console\Commands\SyncWavePayments;
use App\Console\Commands\TelemetryCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ScheduleHeartbeatCommand::class)->description('Updates the last scheduler run time')->everyMinute()->onOneServer();
Schedule::command(CronJob::class)->description('Runs daily to send out invoices, suspend servers, etc.')->dailyAt(config('settings.cronjob_time', '00:00'))->onOneServer();
Schedule::command(FetchEmails::class)->description('Import ticket emails using IMAP')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

if (config('app.telemetry_enabled')) {
    $settings = Settings::getTelemetry();
    Schedule::command(TelemetryCommand::class)->description('Sends telemetry data')->dailyAt($settings['hour'] . ':' . $settings['minute']);
}

Schedule::command(ResellerClubSyncPrices::class, ['--scheduled'])
    ->description('Refresh enabled ResellerClub catalog and prices')->hourly()->withoutOverlapping(30)->onOneServer();

Schedule::command(SyncWavePayments::class)
    ->description('Confirm pending Wave invoice payments')->everyFiveMinutes()->withoutOverlapping(15)->onOneServer();
