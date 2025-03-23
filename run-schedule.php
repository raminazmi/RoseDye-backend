<?php
while (true) {
    $currentHour = date('H');
    if ($currentHour == '00') {
        $output1 = shell_exec('php artisan subscriptions:check');
        if ($output1 === null) {
            file_put_contents('scheduler.log', date('Y-m-d H:i:s') . " - Error executing subscriptions:check\n", FILE_APPEND);
        }

        $output2 = shell_exec('php artisan subscriptions:notify-renewal');
        if ($output2 === null) {
            file_put_contents('scheduler.log', date('Y-m-d H:i:s') . " - Error executing subscriptions:notify-renewal\n", FILE_APPEND);
        }
    }
    sleep(3600);
}
