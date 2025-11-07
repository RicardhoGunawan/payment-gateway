<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('payment_method')->default('midtrans_snap');
            $table->string('midtrans_transaction_id')->nullable();
            $table->string('midtrans_order_id')->unique();
            $table->text('snap_token')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'success', 'failed', 'cancel', 'expire'])->default('pending');
            $table->text('payment_url')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};