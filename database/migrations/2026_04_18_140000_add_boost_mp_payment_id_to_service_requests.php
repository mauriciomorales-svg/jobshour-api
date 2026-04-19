<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('service_requests', 'boost_mp_payment_id')) {
                $table->string('boost_mp_payment_id', 64)->nullable()->after('boosted_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('service_requests', 'boost_mp_payment_id')) {
                $table->dropColumn('boost_mp_payment_id');
            }
        });
    }
};
