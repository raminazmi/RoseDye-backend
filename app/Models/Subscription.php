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
        'status',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    public function checkAndUpdateStatus()
    {
        if (Carbon::parse($this->end_date)->isPast() && $this->status === 'active') {
            $this->update(['status' => 'expired']);
        }
    }
}
