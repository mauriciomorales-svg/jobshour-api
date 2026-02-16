<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. NUDGES: Sistema de frases motivacionales con pesos ──
        Schema::create('nudges', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('category', 30)->default('top'); // top, refuerzo
            $table->integer('weight')->default(50); // 1-100, mayor = más frecuente
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── 2. NICKNAME: Anonimato táctico ──
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 50)->nullable()->after('name');
        });

        // ── 3. FRESH SCORE: Reviews individuales para cálculo últimos 10 ──
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_request_id')->nullable()->constrained('service_requests')->nullOnDelete();
            $table->tinyInteger('stars'); // 1-5
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'created_at']);
        });

        // ── 4. VIDEOS: Ampliar tipos a showcase y vc ──
        DB::statement("ALTER TABLE videos DROP CONSTRAINT IF EXISTS videos_type_check");
        DB::statement("ALTER TABLE videos ADD CONSTRAINT videos_type_check CHECK (type::text = ANY (ARRAY['profile', 'skill_demo', 'job_completion', 'showcase', 'vc']::text[]))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('nudges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });

        DB::statement("ALTER TABLE videos DROP CONSTRAINT IF EXISTS videos_type_check");
        DB::statement("ALTER TABLE videos ADD CONSTRAINT videos_type_check CHECK (type::text = ANY (ARRAY['profile', 'skill_demo', 'job_completion']::text[]))");
    }
};
