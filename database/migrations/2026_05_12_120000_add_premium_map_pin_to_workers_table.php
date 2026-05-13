<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->boolean('show_premium_pin_on_map')->default(false)->after('store_name');
            $table->string('premium_external_store_url', 500)->nullable()->after('show_premium_pin_on_map');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['show_premium_pin_on_map', 'premium_external_store_url']);
        });
    }
};
