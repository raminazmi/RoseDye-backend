<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    protected $fillable = ['phone', 'code', 'expires_at'];

    protected $dates = ['expires_at'];
}
