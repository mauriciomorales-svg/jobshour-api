<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('reviews', 'response')) {
                $table->text('response')->nullable()->after('comment');
            }
            if (!Schema::hasColumn('reviews', 'responded_at')) {
                $table->timestamp('responded_at')->nullable()->after('response');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (Schema::hasColumn('reviews', 'response')) {
                $table->dropColumn('response');
            }
            if (Schema::hasColumn('reviews', 'responded_at')) {
                $table->dropColumn('responded_at');
            }
        });
    }
};
