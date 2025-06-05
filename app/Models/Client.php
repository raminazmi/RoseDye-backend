<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Client extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'phone',
        'current_balance',
        'renewal_balance',
        'original_gift',
        'additional_gift',
        'subscription_number',
        'user_id',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'client_id', 'id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'client_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
