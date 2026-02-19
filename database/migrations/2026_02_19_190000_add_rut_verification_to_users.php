<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'rut')) {
                $table->string('rut', 12)->nullable()->unique()->after('phone');
            }
            if (!Schema::hasColumn('users', 'rut_verified')) {
                $table->boolean('rut_verified')->default(false)->after('rut');
            }
            if (!Schema::hasColumn('users', 'rut_verified_at')) {
                $table->timestamp('rut_verified_at')->nullable()->after('rut_verified');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rut', 'rut_verified', 'rut_verified_at']);
        });
    }
};
