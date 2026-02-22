<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('mp_payment_id')->nullable()->after('status');
            $table->string('mp_preference_id')->nullable()->after('mp_payment_id');
            $table->string('mp_status')->nullable()->after('mp_preference_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['mp_payment_id', 'mp_status']);
        });
    }
};
