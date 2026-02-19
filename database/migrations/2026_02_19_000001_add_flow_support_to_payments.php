<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Agregar campos para soportar ServiceRequest en lugar de solo WorkSession
            if (!Schema::hasColumn('payments', 'service_request_id')) {
                $table->foreignId('service_request_id')->nullable()->after('work_session_id')->constrained('service_requests')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('service_request_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'metadata')) {
                $table->json('metadata')->nullable()->after('transaction_id');
            }
            if (!Schema::hasColumn('payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('completed_at');
            }
            
            // Actualizar payment_method para incluir 'flow'
            // Esto se hace a nivel de aplicación, no requiere cambio en BD
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'service_request_id')) {
                $table->dropForeign(['service_request_id']);
                $table->dropColumn('service_request_id');
            }
            if (Schema::hasColumn('payments', 'client_id')) {
                $table->dropForeign(['client_id']);
                $table->dropColumn('client_id');
            }
            if (Schema::hasColumn('payments', 'metadata')) {
                $table->dropColumn('metadata');
            }
            if (Schema::hasColumn('payments', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
        });
    }
};
