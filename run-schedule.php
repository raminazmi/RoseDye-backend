<?php
while (true) {
    $output1 = shell_exec('php artisan subscriptions:check');
    $output2 = shell_exec('php artisan subscriptions:notify-renewal');

    file_put_contents('scheduler.log', date('Y-m-d H:i:s') . " - subscriptions:check output:\n$output1\n", FILE_APPEND);
    file_put_contents('scheduler.log', date('Y-m-d H:i:s') . " - subscriptions:notify-renewal output:\n$output2\n", FILE_APPEND);
    sleep(1);
}
