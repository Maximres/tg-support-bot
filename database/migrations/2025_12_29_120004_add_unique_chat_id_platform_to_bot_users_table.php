<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Дедупликация на уровне приложения (firstOrCreate) не защищает от гонки при
        // почти одновременных первых сообщениях одного и того же реального пользователя —
        // без ограничения на уровне БД возможно задвоение bot_users для одного chat_id.
        Schema::table('bot_users', function (Blueprint $table) {
            $table->unique(['chat_id', 'platform'], 'bot_users_chat_id_platform_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropUnique('bot_users_chat_id_platform_unique');
        });
    }
};
