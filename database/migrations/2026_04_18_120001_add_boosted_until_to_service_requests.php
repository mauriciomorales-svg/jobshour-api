<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('service_requests', 'boosted_until')) {
                $table->timestamp('boosted_until')->nullable()->after('updated_at');
                $table->index('boosted_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('service_requests', 'boosted_until')) {
                $table->dropIndex(['boosted_until']);
                $table->dropColumn('boosted_until');
            }
        });
    }
};
