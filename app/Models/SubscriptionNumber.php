<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionNumber extends Model
{
    protected $fillable = ['number', 'is_available'];

    public function client()
    {
        return $this->hasOne(Client::class, 'subscription_number_id', 'id');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'subscription_number_id', 'id');
    }
}
