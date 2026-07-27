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
        // Переименована из 2025_01_28_... (сортировалась раньше create_bot_users_table и падала
        // на чистой БД). Проверка hasColumn — чтобы на уже смигрированных окружениях, где эта
        // миграция ранее применилась под старым именем файла, повторный запуск был безопасным no-op.
        if (!Schema::hasTable('bot_users')) {
            return;
        }

        Schema::table('bot_users', function (Blueprint $table) {
            if (!Schema::hasColumn('bot_users', 'phone_number')) {
                $table->string('phone_number')->nullable();
            }
            if (!Schema::hasColumn('bot_users', 'custom_topic_name')) {
                $table->string('custom_topic_name')->nullable();
            }
            if (!Schema::hasColumn('bot_users', 'topic_name_edited')) {
                $table->boolean('topic_name_edited')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'custom_topic_name', 'topic_name_edited']);
        });
    }
};

