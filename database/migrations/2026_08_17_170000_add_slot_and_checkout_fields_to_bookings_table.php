<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('court_number')->default(1)->after('court_id');
            $table->string('customer_name')->nullable()->after('member_id');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->string('payment_method')->nullable()->after('status');
            $table->text('note')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'court_number',
                'customer_name',
                'customer_phone',
                'customer_email',
                'payment_method',
                'note',
            ]);
        });
    }
};
