<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number', 20)->unique();
            $table->date('date');
            $table->decimal('amount', 10, 3);
            $table->timestamps(); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
