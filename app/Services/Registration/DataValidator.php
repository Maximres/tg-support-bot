<?php

namespace App\Services\Registration;

use Illuminate\Support\Facades\Log;

/**
 * Валидатор данных регистрации
 */
class DataValidator
{
    /**
     * Минимальная длина ФИО
     */
    private const MIN_FULL_NAME_LENGTH = 3;

    /**
     * Число слов в ФИО: фамилия, имя и отчество
     */
    private const FULL_NAME_WORDS = 3;

    /**
     * Общее число цифр (вместе с кодом страны) для стран, где длина номера строго известна
     */
    private const PHONE_LENGTH_BY_COUNTRY = [
        '375' => ['digits' => 12, 'example' => '+375291234567'],
        '380' => ['digits' => 12, 'example' => '+380501234567'],
        '7' => ['digits' => 11, 'example' => '+79123456789'],
    ];

    /**
     * Максимальная длина ФИО
     */
    private const MAX_FULL_NAME_LENGTH = 200;

    /**
     * Валидация ФИО
     * Edge cases: только пробелы, слишком короткое/длинное, пустое значение
     *
     * @param string|null $fullName
     *
     * @return array ['valid' => bool, 'error' => string|null, 'normalized' => string|null]
     */
    public function validateFullName(?string $fullName): array
    {
        // Edge case: пустое значение
        if (empty($fullName)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.full_name_required'),
                'normalized' => null,
            ];
        }

        // Edge case: только пробелы - trim и проверка
        $normalized = trim($fullName);
        
        if (empty($normalized)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.full_name_required'),
                'normalized' => null,
            ];
        }

        // Edge case: слишком короткое
        if (mb_strlen($normalized) < self::MIN_FULL_NAME_LENGTH) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.full_name_too_short', [
                    'min' => self::MIN_FULL_NAME_LENGTH,
                ]),
                'normalized' => null,
            ];
        }

        // ФИО — только буквы (допустимы пробел, дефис, апостроф, точка) и ровно три слова
        // (фамилия, имя, отчество): «123», «Максим», «Иванов Иван» и подобное не считаем полным ФИО
        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (preg_match('/[^\p{L}\s\-.\']/u', $normalized)
            || !preg_match('/\p{L}/u', $normalized)
            || count($words) !== self::FULL_NAME_WORDS) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.full_name_invalid'),
                'normalized' => null,
            ];
        }

        // Edge case: слишком длинное - обрезаем с предупреждением
        if (mb_strlen($normalized) > self::MAX_FULL_NAME_LENGTH) {
            Log::warning('DataValidator: ФИО слишком длинное, обрезаем', [
                'original_length' => mb_strlen($normalized),
                'max_length' => self::MAX_FULL_NAME_LENGTH,
            ]);
            
            $normalized = mb_substr($normalized, 0, self::MAX_FULL_NAME_LENGTH);
            
            return [
                'valid' => true,
                'error' => __('messages.registration.validation.full_name_truncated'),
                'normalized' => $normalized,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'normalized' => $normalized,
        ];
    }

    /**
     * Валидация телефона
     * Edge cases: неправильный формат, пустое значение, нормализация формата
     * Принимает любые международные номера
     *
     * @param string|null $phone
     *
     * @return array ['valid' => bool, 'error' => string|null, 'normalized' => string|null]
     */
    public function validatePhone(?string $phone): array
    {
        // Edge case: пустое значение
        if (empty($phone)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.phone_required'),
                'normalized' => null,
            ];
        }

        // Нормализация: удаляем все нецифровые символы кроме +
        $normalized = preg_replace('/[^\d+]/', '', $phone);

        // Edge case: только пробелы или пусто после нормализации
        if (empty($normalized)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.phone_invalid'),
                'normalized' => null,
            ];
        }

        // Если номер начинается с цифры (без +), добавляем +
        if (preg_match('/^\d/', $normalized)) {
            $normalized = '+' . $normalized;
        }

        // Проверка формата: должен начинаться с + и содержать минимум 10 цифр (код страны + номер)
        // Международные номера обычно содержат от 10 до 15 цифр (включая код страны)
        // Формат: +[код страны][номер]
        $digitsOnly = preg_replace('/[^\d]/', '', $normalized);
        
        if (!preg_match('/^\+/', $normalized)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.phone_invalid'),
                'normalized' => null,
            ];
        }

        // Проверяем количество цифр (минимум 10 для международного номера)
        if (strlen($digitsOnly) < 10 || strlen($digitsOnly) > 15) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.phone_invalid'),
                'normalized' => null,
            ];
        }

        // Для стран с известной длиной номера проверяем её строго: лишняя или пропущенная цифра — опечатка
        foreach (self::PHONE_LENGTH_BY_COUNTRY as $code => $rule) {
            if (str_starts_with($digitsOnly, (string)$code) && strlen($digitsOnly) !== $rule['digits']) {
                return [
                    'valid' => false,
                    'error' => __('messages.registration.validation.phone_invalid_length', [
                        'code' => $code,
                        'digits' => $rule['digits'],
                        'example' => $rule['example'],
                    ]),
                    'normalized' => null,
                ];
            }
        }

        return [
            'valid' => true,
            'error' => null,
            'normalized' => $normalized,
        ];
    }

    /**
     * Валидация email
     * Edge cases: неправильный формат, пустое значение
     *
     * @param string|null $email
     *
     * @return array ['valid' => bool, 'error' => string|null, 'normalized' => string|null]
     */
    public function validateEmail(?string $email): array
    {
        // Edge case: пустое значение
        if (empty($email)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.email_required'),
                'normalized' => null,
            ];
        }

        // Edge case: только пробелы - trim
        $normalized = trim($email);
        
        if (empty($normalized)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.email_required'),
                'normalized' => null,
            ];
        }

        // Валидация через фильтр PHP
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'error' => __('messages.registration.validation.email_invalid'),
                'normalized' => null,
            ];
        }

        // Нормализация: lowercase
        $normalized = mb_strtolower($normalized);

        return [
            'valid' => true,
            'error' => null,
            'normalized' => $normalized,
        ];
    }
}

