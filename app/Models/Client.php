<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Client extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company_name',
        'address',
        'current_balance',
        'renewal_balance',
        'subscription_number',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
