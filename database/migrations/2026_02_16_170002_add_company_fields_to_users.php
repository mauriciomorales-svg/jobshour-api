<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_company')->default(false)->after('is_pioneer');
            $table->string('company_rut', 20)->nullable()->after('is_company');
            $table->string('company_razon_social', 200)->nullable()->after('company_rut');
            $table->string('company_giro', 200)->nullable()->after('company_razon_social');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_company', 'company_rut', 'company_razon_social', 'company_giro']);
        });
    }
};
