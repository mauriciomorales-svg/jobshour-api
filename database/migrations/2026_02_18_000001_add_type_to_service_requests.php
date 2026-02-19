<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('type', 50)->default('fixed_job')->after('category_id');
            $table->index('type');
        });

        // Agregar constraint para validar tipos permitidos
        DB::statement("
            ALTER TABLE service_requests 
            ADD CONSTRAINT service_requests_type_check 
            CHECK (type IN ('ride_share', 'express_errand', 'fixed_job'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_type_check');
        
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
