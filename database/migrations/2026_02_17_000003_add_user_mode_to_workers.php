<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('user_mode', 20)->default('socio')->after('availability_status');
            $table->index('user_mode');
        });

        DB::statement("ALTER TABLE workers ADD CONSTRAINT workers_user_mode_check CHECK (user_mode IN ('socio', 'empresa'))");
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('user_mode');
        });
    }
};
