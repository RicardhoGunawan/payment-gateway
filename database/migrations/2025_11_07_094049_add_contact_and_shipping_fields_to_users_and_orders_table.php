<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('address')->nullable()->after('phone');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_name')->nullable()->after('status');
            $table->string('shipping_address')->nullable()->after('shipping_name');
            $table->string('shipping_phone')->nullable()->after('shipping_address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_name', 'shipping_address', 'shipping_phone']);
        });
    }
};
