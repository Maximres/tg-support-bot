<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('bot_users', 'topic_closed_at')) {
            return;
        }

        Schema::table('bot_users', function (Blueprint $table) {
            $table->timestamp('topic_closed_at')->nullable()->after('topic_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('bot_users', 'topic_closed_at')) {
            return;
        }

        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropColumn('topic_closed_at');
        });
    }
};
