<?php
while (true) {
    $targetHour = '00';
    $currentHour = date('H');

    if ($currentHour == $targetHour) {
        $output1 = shell_exec('php artisan subscriptions:check');
        $output2 = shell_exec('php artisan subscriptions:notify-renewal');

        file_put_contents(
            'scheduler.log',
            date('Y-m-d H:i:s') . " - subscriptions:check output:\n$output1\n" .
                date('Y-m-d H:i:s') . " - subscriptions:notify-renewal output:\n$output2\n",
            FILE_APPEND
        );

        sleep(3600 * 23);
    } else {
        sleep(1800);
    }
}
