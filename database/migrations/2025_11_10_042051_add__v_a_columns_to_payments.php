<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Add new columns for Virtual Account payments
            $table->string('va_number')->nullable()->after('deeplink_url');
            $table->string('bank')->nullable()->after('va_number');
            
            // Add columns for Mandiri Bill
            $table->string('bill_key')->nullable()->after('bank');
            $table->string('biller_code')->nullable()->after('bill_key');
            
            // Add expiry time
            $table->timestamp('expiry_time')->nullable()->after('raw_response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'va_number',
                'bank',
                'bill_key',
                'biller_code',
                'expiry_time',
            ]);
        });
    }
};