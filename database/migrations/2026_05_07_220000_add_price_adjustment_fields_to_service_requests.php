<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('service_requests', 'adjusted_price')) {
                $table->decimal('adjusted_price', 10, 2)->nullable()->after('final_price');
            }
            if (! Schema::hasColumn('service_requests', 'price_adjustment_reason')) {
                $table->string('price_adjustment_reason', 500)->nullable()->after('adjusted_price');
            }
            if (! Schema::hasColumn('service_requests', 'price_adjusted_at')) {
                $table->timestamp('price_adjusted_at')->nullable()->after('price_adjustment_reason');
            }
            if (! Schema::hasColumn('service_requests', 'client_approved_adjustment')) {
                $table->boolean('client_approved_adjustment')->default(false)->after('price_adjusted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $cols = [];
            foreach (['client_approved_adjustment', 'price_adjusted_at', 'price_adjustment_reason', 'adjusted_price'] as $c) {
                if (Schema::hasColumn('service_requests', $c)) {
                    $cols[] = $c;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
