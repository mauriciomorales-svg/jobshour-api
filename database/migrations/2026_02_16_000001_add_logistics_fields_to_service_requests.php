<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->enum('carga_tipo', ['sobre', 'paquete', 'bulto'])->nullable()->after('description');
            $table->decimal('carga_peso', 8, 2)->nullable()->after('carga_tipo');
            $table->string('pickup_address')->nullable()->after('carga_peso');
            $table->string('delivery_address')->nullable()->after('pickup_address');
            $table->decimal('pickup_lat', 10, 8)->nullable()->after('delivery_address');
            $table->decimal('pickup_lng', 11, 8)->nullable()->after('pickup_lat');
            $table->decimal('delivery_lat', 10, 8)->nullable()->after('pickup_lng');
            $table->decimal('delivery_lng', 11, 8)->nullable()->after('delivery_lat');
            $table->string('delivery_photo')->nullable()->after('delivery_lng');
            $table->text('delivery_signature')->nullable()->after('delivery_photo');
            $table->timestamp('started_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn([
                'carga_tipo',
                'carga_peso',
                'pickup_address',
                'delivery_address',
                'pickup_lat',
                'pickup_lng',
                'delivery_lat',
                'delivery_lng',
                'delivery_photo',
                'delivery_signature',
                'started_at',
            ]);
        });
    }
};
