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
            if (!Schema::hasColumn('store_orders', 'confirmation_code')) {
                $table->string('confirmation_code', 4)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_orders')) {
            return;
        }

        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'confirmation_code')) {
                $table->dropColumn('confirmation_code');
            }
        });
    }
};

