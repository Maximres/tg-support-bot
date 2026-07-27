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
        Schema::table('safe_codes', function (Blueprint $table) {
            $table->string('type', 32)->default('safe')->after('code');
            $table->index(['type', 'id'], 'safe_codes_type_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('safe_codes', function (Blueprint $table) {
            $table->dropIndex('safe_codes_type_id_index');
            $table->dropColumn('type');
        });
    }
};
