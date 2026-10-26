<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Учёт массовых рассылок: что и кому отправлено — чтобы можно было удалить или исправить рассылку у клиентов
     * и не разослать одно и то же сообщение дважды.
     */
    public function up(): void
    {
        if (!Schema::hasTable('broadcasts')) {
            Schema::create('broadcasts', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('source_chat_id');
                $table->bigInteger('source_message_id');
                $table->json('source_message_ids');
                $table->bigInteger('update_id')->nullable();
                $table->string('kind', 16);
                $table->boolean('has_follow_up')->default(false);
                $table->string('status', 16)->default('sending');
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();

                // Одно сообщение из топика рассылки отправляется один раз, даже если Telegram повторит обновление
                $table->unique(['source_chat_id', 'source_message_id'], 'broadcasts_source_unique');
            });
        }

        if (!Schema::hasTable('broadcast_messages')) {
            Schema::create('broadcast_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
                $table->unsignedBigInteger('bot_user_id');
                $table->bigInteger('chat_id');
                $table->json('message_ids');
                $table->timestamps();

                $table->index('broadcast_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_messages');
        Schema::dropIfExists('broadcasts');
    }
};
