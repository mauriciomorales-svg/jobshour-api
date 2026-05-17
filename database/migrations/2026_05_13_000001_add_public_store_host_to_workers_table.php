<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('public_store_host', 255)->nullable()->unique()->after('store_name');
            $table->timestamp('public_store_host_verified_at')->nullable()->after('public_store_host');
            $table->string('public_store_host_verify_token', 64)->nullable()->after('public_store_host_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn([
                'public_store_host',
                'public_store_host_verified_at',
                'public_store_host_verify_token',
            ]);
        });
    }
};
