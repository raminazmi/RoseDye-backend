<?php

namespace Database\Seeders;

use App\Models\SubscriptionNumber;
use Illuminate\Database\Seeder;

class SubscriptionNumberSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1001; $i <= 1100; $i++) {
            SubscriptionNumber::create([
                'number' => (string)$i,
                'is_available' => true,
            ]);
        }
    }
}
