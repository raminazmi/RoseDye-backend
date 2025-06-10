<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Client extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'phone',
        'current_balance',
        'renewal_balance',
        'subscription_number_id',
        'original_gift',
        'additional_gift',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionNumber()
    {
        return $this->belongsTo(SubscriptionNumber::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
