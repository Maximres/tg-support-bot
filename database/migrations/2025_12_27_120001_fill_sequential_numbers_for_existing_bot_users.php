<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Заполняем порядковые номера для существующих пользователей по их ID
        DB::statement('
            UPDATE bot_users 
            SET sequential_number = id 
            WHERE sequential_number IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Очищаем порядковые номера (необязательно, но для чистоты)
        DB::statement('
            UPDATE bot_users 
            SET sequential_number = NULL
        ');
    }
};

