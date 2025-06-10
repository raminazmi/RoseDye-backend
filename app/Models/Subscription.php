<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'client_id',
        'plan_name',
        'price',
        'start_date',
        'end_date',
        'duration_in_days',
        'subscription_number_id',
        'status',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    public function subscriptionNumber()
    {
        return $this->belongsTo(SubscriptionNumber::class);
    }

    public function checkAndUpdateStatus()
    {
        if ($this->status === 'active' && now()->gt($this->end_date)) {
            $this->update(['status' => 'expired']);
            $this->subscriptionNumber()->update(['is_available' => true]);
        }
    }
}
