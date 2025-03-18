<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use Carbon\Carbon;
use App\Http\Controllers\API\SubscriptionController;

class CheckSubscriptions extends Command
{
    protected $signature = 'subscriptions:check';
    protected $description = 'Check and update subscription statuses daily';

    public function handle()
    {
        $subscriptions = Subscription::where('status', 'active')->get();
        foreach ($subscriptions as $subscription) {
            $subscription->checkAndUpdateStatus();
        }

        $tomorrow = Carbon::now()->addDay()->toDateString();
        $expiringSubscriptions = Subscription::where('end_date', $tomorrow)
            ->where('status', 'active')
            ->get();

        $subscriptionController = new SubscriptionController();
        foreach ($expiringSubscriptions as $subscription) {
            $subscriptionController->sendExpirationNotification($subscription->id);
        }

        $this->info('تم التحقق من الاشتراكات وإرسال التنبيهات بنجاح');
    }
}
