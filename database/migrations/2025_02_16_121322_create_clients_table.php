<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->decimal('current_balance', 8, 3);
            $table->decimal('renewal_balance', 8, 3);
            $table->decimal('original_gift', 8, 3)->nullable();
            $table->decimal('additional_gift', 8, 3)->nullable();
            $table->string('subscription_number', 20)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
