<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('workers', 'is_seller')) {
            Schema::table('workers', function (Blueprint $table) {
                $table->boolean('is_seller')->default(false)->after('is_verified');
            });
        }
        if (!Schema::hasColumn('workers', 'store_name')) {
            Schema::table('workers', function (Blueprint $table) {
                $table->string('store_name', 255)->nullable()->after('is_seller');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workers', 'is_seller')) {
            Schema::table('workers', function (Blueprint $table) {
                $table->dropColumn('is_seller');
            });
        }
        if (Schema::hasColumn('workers', 'store_name')) {
            Schema::table('workers', function (Blueprint $table) {
                $table->dropColumn('store_name');
            });
        }
    }
};
