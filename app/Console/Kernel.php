<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\SendExpiringNotifications; // تصحيح مسار الاستيراد

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('notifications:send-expiring')
            ->everyMinute()
            ->appendOutputTo(storage_path('logs/scheduler.log'));
    }

    protected $commands = [
        SendExpiringNotifications::class, // استخدام الكلاس مباشرة
    ];

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
