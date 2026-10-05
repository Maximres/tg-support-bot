<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Настройки, которыми администраторы управляют из Telegram (ключ-значение)
 *
 * @property string      $key
 * @property string|null $value
 */
class BotSetting extends Model
{
    protected $table = 'bot_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @param string      $key
     * @param string|null $default
     *
     * @return string|null
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $setting = static::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    /**
     * @param string      $key
     * @param string|null $value
     *
     * @return void
     */
    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
