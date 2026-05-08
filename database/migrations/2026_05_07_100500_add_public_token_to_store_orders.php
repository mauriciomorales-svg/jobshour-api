<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_orders')) {
            return;
        }

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'public_token')) {
                $table->string('public_token', 64)->nullable()->after('confirmation_code');
                $table->index('public_token');
            }
        });

        DB::table('store_orders')
            ->whereNull('public_token')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    DB::table('store_orders')
                        ->where('id', $order->id)
                        ->update(['public_token' => Str::random(48)]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('store_orders')) {
            return;
        }

        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'public_token')) {
                $table->dropIndex(['public_token']);
                $table->dropColumn('public_token');
            }
        });
    }
};
