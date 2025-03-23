<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckSubscriptions extends Command
{
    protected $signature = 'subscriptions:check';
    protected $description = 'Check and update subscription statuses and send expiration notifications';

    public function handle()
    {
        Log::info('بدء تشغيل الأمر subscriptions:check');

        $subscriptions = Subscription::where('status', 'active')->get();
        Log::info('عدد الاشتراكات النشطة: ' . $subscriptions->count());

        foreach ($subscriptions as $subscription) {
            $subscription->checkAndUpdateStatus();
            if ($subscription->status === 'expired') {
                Log::info("تم تحديث حالة الاشتراك {$subscription->id} إلى منتهي.");
            }
        }

        $today = Carbon::now()->toDateString();
        $newlyExpiredSubscriptions = Subscription::where('end_date', $today)
            ->where('status', 'expired')
            ->get();

        Log::info('عدد الاشتراكات التي انتهت اليوم: ' . $newlyExpiredSubscriptions->count());

        foreach ($newlyExpiredSubscriptions as $subscription) {
            if ($subscription->client && $subscription->client->phone) {
                $message = "تنبيه: اشتراكك رقم {$subscription->client->subscription_number} قد انتهى بتاريخ {$subscription->end_date}. يرجى التجديد.";

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
                        Log::info("تم إرسال الإشعار للعميل {$subscription->client->subscription_number} بنجاح (اشتراك منتهي).");
                        $this->info("تم إرسال الإشعار للعميل {$subscription->client->subscription_number} بنجاح (اشتراك منتهي).");
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

        $tomorrow = Carbon::now()->addDay()->toDateString();
        $expiringSubscriptions = Subscription::where('end_date', $tomorrow)
            ->where('status', 'active')
            ->get();

        Log::info('عدد الاشتراكات التي ستنتهي غدًا: ' . $expiringSubscriptions->count());

        foreach ($expiringSubscriptions as $subscription) {
            if ($subscription->client && $subscription->client->phone) {
                $message = "تنبيه: اشتراكك رقم {$subscription->client->subscription_number} على وشك الانتهاء بتاريخ {$subscription->end_date}.";

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
                        Log::info("تم إرسال الإشعار للعميل {$subscription->client->subscription_number} بنجاح (على وشك الانتهاء).");
                        $this->info("تم إرسال الإشعار للعميل {$subscription->client->subscription_number} بنجاح (على وشك الانتهاء).");
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

        Log::info('تم التحقق من الاشتراكات وإرسال التنبيهات بنجاح.');
        $this->info('تم التحقق من الاشتراكات وإرسال التنبيهات بنجاح.');
    }
}
