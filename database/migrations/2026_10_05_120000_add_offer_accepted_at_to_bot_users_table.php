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
        if (Schema::hasColumn('bot_users', 'offer_accepted_at')) {
            return;
        }

        Schema::table('bot_users', function (Blueprint $table) {
            $table->timestamp('offer_accepted_at')->nullable()->after('registration_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('bot_users', 'offer_accepted_at')) {
            return;
        }

        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropColumn('offer_accepted_at');
        });
    }
};
