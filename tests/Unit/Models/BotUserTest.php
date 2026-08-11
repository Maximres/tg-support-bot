<?php

namespace Tests\Unit\Models;

use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_completed_at_is_cleared_when_full_name_missing(): void
    {
        $botUser = BotUser::getUserByChatId(time(), 'tg');

        $botUser->phone_number = '+375291234567';
        $botUser->email = 'test@example.com';
        $botUser->registration_completed_at = now();
        $botUser->save();

        $this->assertNull($botUser->fresh()->registration_completed_at);
    }

    public function test_registration_completed_at_is_kept_when_all_fields_present(): void
    {
        $botUser = BotUser::getUserByChatId(time(), 'tg');

        $botUser->full_name = 'Иван Иванов';
        $botUser->phone_number = '+375291234567';
        $botUser->email = 'test@example.com';
        $botUser->registration_completed_at = now();
        $botUser->save();

        $this->assertNotNull($botUser->fresh()->registration_completed_at);
    }
}
