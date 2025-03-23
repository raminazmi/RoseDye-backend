<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class NotifySubscriptionRenewal extends Command
{
    protected $signature = 'subscriptions:notify-renewal';
    protected $description = 'Notify clients about subscriptions expiring within 7 days';

    public function handle()
    {
        Log::info('بدء مهمة إرسال إشعارات التجديد للاشتراكات التي ستنتهي خلال 7 أيام');

        $subscriptions = Subscription::where('end_date', '<=', now()->addDays(7))
            ->where('status', 'active')
            ->get();

        Log::info('عدد الاشتراكات التي ستنتهي خلال 7 أيام: ' . $subscriptions->count());

        foreach ($subscriptions as $subscription) {
            if ($subscription->client && $subscription->client->phone) {
                $message = "تنبيه: اشتراكك رقم {$subscription->client->subscription_number} سينتهي بتاريخ {$subscription->end_date}. يرجى التجديد.";

                try {
                    $client = new \GuzzleHttp\Client();
                    $token = env('ULTRAMSG_TOKEN');
                    $url = 'https://api.ultramsg.com/instance60138/messages/chat?token=' . urlencode($token);

                    $response = $client->post($url, [
                        'form_params' => [
                            'to' => $subscription->client->phone,
                            'body' => $message,
                        ],
                        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    ]);

                    $result = json_decode($response->getBody(), true);
                    if (!isset($result['sent']) || $result['sent'] !== 'true') {
                        Log::error("فشل إرسال الإشعار للعميل {$subscription->client->subscription_number}: " . ($result['error'] ?? 'خطأ غير محدد'));
                        $this->error("فشل إرسال الإشعار للعميل {$subscription->client->subscription_number}: " . ($result['error'] ?? 'خطأ غير محدد'));
                    } else {
                        Log::info("تم إرسال الإشعار للعميل {$subscription->client->subscription_number} بنجاح.");
                        $this->info("تم إرسال الإشعار للعميل {$subscription->client->subscription_number} بنجاح.");
                    }
                } catch (\Exception $e) {
                    Log::error("حدث خطأ أثناء إرسال الإشعار للعميل {$subscription->client->subscription_number}: " . $e->getMessage());
                    $this->error("حدث خطأ أثناء إرسال الإشعار للعميل {$subscription->client->subscription_number}: " . $e->getMessage());
                }
            } else {
                Log::warning("لا يوجد رقم هاتف للعميل {$subscription->client->subscription_number}.");
                $this->warn("لا يوجد رقم هاتف للعميل {$subscription->client->subscription_number}.");
            }
        }

        $this->info('تم إرسال إشعارات التجديد بنجاح.');
    }
}
