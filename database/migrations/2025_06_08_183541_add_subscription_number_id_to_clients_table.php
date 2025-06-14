<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_number_id')->nullable();
            $table->foreign('subscription_number_id')->references('id')->on('subscription_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['subscription_number_id']);
            $table->dropColumn('subscription_number_id');
        });
    }
};
