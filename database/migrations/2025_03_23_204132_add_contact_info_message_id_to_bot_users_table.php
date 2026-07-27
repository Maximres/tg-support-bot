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
        // Переименована из 2025_01_21_... (сортировалась раньше create_bot_users_table и падала
        // на чистой БД). Проверка hasColumn — чтобы на уже смигрированных окружениях, где эта
        // миграция ранее применилась под старым именем файла, повторный запуск был безопасным no-op.
        if (Schema::hasTable('bot_users') && !Schema::hasColumn('bot_users', 'contact_info_message_id')) {
            Schema::table('bot_users', function (Blueprint $table) {
                $table->integer('contact_info_message_id')->nullable()->after('topic_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bot_users', 'contact_info_message_id')) {
            Schema::table('bot_users', function (Blueprint $table) {
                $table->dropColumn('contact_info_message_id');
            });
        }
    }
};

