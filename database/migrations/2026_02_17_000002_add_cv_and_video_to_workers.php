<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('cv_path')->nullable()->after('bio');
            $table->string('video_cv_path')->nullable()->after('cv_path');
            $table->integer('video_cv_duration')->nullable()->after('video_cv_path');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['cv_path', 'video_cv_path', 'video_cv_duration']);
        });
    }
};
