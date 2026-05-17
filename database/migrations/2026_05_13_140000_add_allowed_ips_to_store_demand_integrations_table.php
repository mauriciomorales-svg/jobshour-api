<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_demand_integrations', function (Blueprint $table) {
            $table->json('allowed_ips')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('store_demand_integrations', function (Blueprint $table) {
            $table->dropColumn('allowed_ips');
        });
    }
};
