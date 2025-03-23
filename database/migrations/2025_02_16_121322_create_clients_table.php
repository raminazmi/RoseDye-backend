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
            $table->integer('current_balance');
            $table->integer('renewal_balance');
            $table->integer('original_gift')->nullable();
            $table->integer('additional_gift')->nullable();
            $table->string('subscription_number', 20)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
