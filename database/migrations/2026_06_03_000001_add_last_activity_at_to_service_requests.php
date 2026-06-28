<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_requests', 'last_activity_at')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('service_requests', 'last_activity_at')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
