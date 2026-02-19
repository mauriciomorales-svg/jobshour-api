<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('pin_expires_at');
            $table->integer('workers_needed')->default(1)->after('scheduled_at');
            $table->string('recurrence', 50)->nullable()->after('workers_needed'); // once, daily, weekly, custom
            $table->json('recurrence_days')->nullable()->after('recurrence'); // [1,3,5] = lun,mié,vie
            $table->integer('workers_accepted')->default(0)->after('recurrence_days');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_business')->default(false)->after('is_pioneer');
            $table->string('business_name', 255)->nullable()->after('is_business');
            $table->string('business_type', 100)->nullable()->after('business_name'); // restaurant, store, clinic, etc.
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'workers_needed', 'recurrence', 'recurrence_days', 'workers_accepted']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_business', 'business_name', 'business_type']);
        });
    }
};
