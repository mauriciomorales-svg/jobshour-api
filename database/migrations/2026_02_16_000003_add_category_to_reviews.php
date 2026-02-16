<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('worker_id')->constrained()->nullOnDelete();
        });

        // Migrar datos existentes: asignar category_id desde service_requests
        DB::statement('
            UPDATE reviews r
            SET category_id = sr.category_id
            FROM service_requests sr
            WHERE r.service_request_id = sr.id
            AND r.category_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
