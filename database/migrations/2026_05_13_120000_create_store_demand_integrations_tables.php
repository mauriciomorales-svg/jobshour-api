<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_demand_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('store_demand_partner_publishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_demand_integration_id')->constrained('store_demand_integrations')->cascadeOnDelete();
            $table->string('dedupe_key', 128);
            $table->foreignId('service_request_id')->constrained('service_requests')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_demand_integration_id', 'dedupe_key'], 'store_demand_partner_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_demand_partner_publishes');
        Schema::dropIfExists('store_demand_integrations');
    }
};
