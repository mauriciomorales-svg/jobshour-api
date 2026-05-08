<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('store_orders')) {
            return;
        }

        Schema::table('store_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('store_orders', 'integrated_quote_id')) {
                $table->unsignedBigInteger('integrated_quote_id')->nullable()->after('mp_preference_id');
                $table->index(['integrated_quote_id']);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_orders')) {
            return;
        }

        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'integrated_quote_id')) {
                $table->dropIndex(['integrated_quote_id']);
                $table->dropColumn('integrated_quote_id');
            }
        });
    }
};
