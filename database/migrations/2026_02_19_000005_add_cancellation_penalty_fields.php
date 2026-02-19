<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('service_requests', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('service_requests', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
            if (!Schema::hasColumn('service_requests', 'penalty_amount')) {
                $table->decimal('penalty_amount', 10, 2)->default(0)->after('cancellation_reason');
            }
            if (!Schema::hasColumn('service_requests', 'penalty_applied')) {
                $table->boolean('penalty_applied')->default(false)->after('penalty_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('service_requests', 'penalty_applied')) {
                $table->dropColumn('penalty_applied');
            }
            if (Schema::hasColumn('service_requests', 'penalty_amount')) {
                $table->dropColumn('penalty_amount');
            }
            if (Schema::hasColumn('service_requests', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
            if (Schema::hasColumn('service_requests', 'cancelled_by')) {
                $table->dropForeign(['cancelled_by']);
                $table->dropColumn('cancelled_by');
            }
            if (Schema::hasColumn('service_requests', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
