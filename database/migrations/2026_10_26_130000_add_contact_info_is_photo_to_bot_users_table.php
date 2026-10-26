<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Контактная карточка в теме может быть фотографией профиля клиента с подписью: тогда править нужно подпись, а не текст.
     */
    public function up(): void
    {
        if (Schema::hasColumn('bot_users', 'contact_info_is_photo')) {
            return;
        }

        Schema::table('bot_users', function (Blueprint $table) {
            $table->boolean('contact_info_is_photo')->default(false)->after('contact_info_message_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bot_users', 'contact_info_is_photo')) {
            Schema::table('bot_users', function (Blueprint $table) {
                $table->dropColumn('contact_info_is_photo');
            });
        }
    }
};
