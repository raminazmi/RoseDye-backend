<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendExpiringNotifications extends Command
{
    protected $signature = 'notifications:send-expiring';

    protected $description = 'Send WhatsApp notifications for subscriptions starting within 7 days';

    public function handle()
    {
        Log::info('Starting command');
        try {
            $now = Carbon::now('Asia/Kuwait');
            $sevenDaysLater = $now->copy()->addDays(7);

            $subscriptions = Subscription::where('status', 'active')
                ->whereBetween('end_date', [$now, $sevenDaysLater])
                ->get();

            if ($subscriptions->isEmpty()) {
                Log::info('No subscriptions starting soon found for notification.');
                $this->info('No subscriptions starting soon found.');
                return 0;
            }

            $client = new Client();
            $token = env('ULTRAMSG_TOKEN', '');
            if (empty($token)) {
                Log::error('ULTRAMSG_TOKEN is not set in .env');
                $this->error('ULTRAMSG_TOKEN is not set in .env');
                return 1;
            }

            $url = 'https://api.ultramsg.com/instance60138/messages/chat?token=' . urlencode($token);

            $this->info("Processing {$subscriptions->count()} subscriptions.");

            foreach ($subscriptions as $subscription) {
                if (!$subscription->client || !$subscription->client->phone) {
                    Log::warning("No client or phone number for subscription ID: {$subscription->id}");
                    $this->warn("No client or phone number for subscription ID: {$subscription->id}");
                    continue;
                }

                $endDate = Carbon::parse($subscription->end_date, 'Asia/Kuwait')->format('d-m-Y');
                $message = "تنبيه: اشتراكك رقم {$subscription->client->subscription_number} على وشك الانتهاء بتاريخ {$endDate}. المتبقي " . $subscription->client->renewal_balance . " + " .  $subscription->client->additional_gift . " هدية الرجاء استخدامه قبل الانتهاء";

                try {
                    $response = $client->post($url, [
                        'form_params' => [
                            'to' => $subscription->client->phone,
                            'body' => $message,
                        ],
                        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    ]);

                    $result = json_decode($response->getBody(), true);
                    if (!isset($result['sent']) || $result['sent'] !== 'true') {
                        Log::error("Failed to send notification for subscription ID: {$subscription->id}. Error: " . ($result['error'] ?? 'Unknown error'));
                        $this->error("Failed to send notification for subscription ID: {$subscription->id}");
                    } else {
                        Log::info("Notification sent successfully for subscription ID: {$subscription->id}");
                        $this->info("Notification sent successfully for subscription ID: {$subscription->id}");
                    }
                } catch (\Exception $e) {
                    Log::error("Error sending notification for subscription ID: {$subscription->id}. Message: {$e->getMessage()}");
                    $this->error("Error sending notification for subscription ID: {$subscription->id}: {$e->getMessage()}");
                }
            }

            Log::info('Completed notifications:send-expiring command');
            $this->info('Subscription start notifications processed successfully.');
            return 0;
        } catch (\Exception $e) {
            Log::error("Command notifications:send-expiring failed: {$e->getMessage()}");
            $this->error("Task failed: {$e->getMessage()}");
            return 1;
        }
    }
}
