<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('service_requests', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('service_requests', 'pause_reason')) {
                $table->string('pause_reason', 500)->nullable()->after('paused_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('service_requests', 'pause_reason')) {
                $table->dropColumn('pause_reason');
            }
            if (Schema::hasColumn('service_requests', 'paused_at')) {
                $table->dropColumn('paused_at');
            }
        });
    }
};
