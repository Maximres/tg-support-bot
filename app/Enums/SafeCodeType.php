<?php

namespace App\Enums;

/**
 * Типы значений, выдаваемых доверенным сотрудникам через личный кабинет бота
 */
enum SafeCodeType: string
{
    case SAFE = 'safe';
    case BUILDING = 'building';
    case ORG_LINK = 'org_link';

    /**
     * Требуется ли доверие (is_trusted) для получения значения этого типа
     */
    public function requiresTrust(): bool
    {
        return $this !== self::ORG_LINK;
    }

    /**
     * Является ли значение ссылкой (а не кодом)
     */
    public function isUrl(): bool
    {
        return $this === self::ORG_LINK;
    }

    /**
     * callback_data кнопки "Показать значение" для этого типа
     */
    public function callbackData(): string
    {
        return 'access_show_' . $this->value;
    }

    /**
     * Команда администратора для задания нового значения этого типа
     */
    public function command(): string
    {
        return match ($this) {
            self::SAFE => '/set_code',
            self::BUILDING => '/set_building_code',
            self::ORG_LINK => '/set_org_link',
        };
    }

    /**
     * Пример значения для подсказки администратору
     */
    public function exampleValue(): string
    {
        return match ($this) {
            self::SAFE, self::BUILDING => '1234',
            self::ORG_LINK => 'https://example.com',
        };
    }

    /**
     * Человекочитаемое название для подстановки в тексты
     */
    public function label(): string
    {
        return match ($this) {
            self::SAFE => 'Код от сейфа',
            self::BUILDING => 'Код от здания',
            self::ORG_LINK => 'Ссылка на орг. информацию',
        };
    }

    /**
     * Текст кнопки в сообщении с доступом
     */
    public function buttonLabel(): string
    {
        return match ($this) {
            self::SAFE => '🔐 Показать код от сейфа',
            self::BUILDING => '🔐 Показать код от здания',
            self::ORG_LINK => 'ℹ️ Орг. информация',
        };
    }
}
