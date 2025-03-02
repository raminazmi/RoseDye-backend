<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            \App\Models\Subscription::where('end_date', '<=', now()->addDays(7))
                ->where('status', 'active')
                ->each(function ($subscription) {
                    $subscription->client->notify(
                        new \App\Notifications\SubscriptionRenewalNotification()
                    );
                });
        })->daily();
        $schedule->command('invoices:process-overdue')
            ->everySecond();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
