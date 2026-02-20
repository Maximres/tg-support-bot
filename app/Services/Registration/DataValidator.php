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
                'error' => __('registration.validation.full_name_required'),
                'normalized' => null,
            ];
        }

        // Edge case: только пробелы - trim и проверка
        $normalized = trim($fullName);
        
        if (empty($normalized)) {
            return [
                'valid' => false,
                'error' => __('registration.validation.full_name_required'),
                'normalized' => null,
            ];
        }

        // Edge case: слишком короткое
        if (mb_strlen($normalized) < self::MIN_FULL_NAME_LENGTH) {
            return [
                'valid' => false,
                'error' => __('registration.validation.full_name_too_short', [
                    'min' => self::MIN_FULL_NAME_LENGTH,
                ]),
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
                'error' => __('registration.validation.full_name_truncated'),
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
                'error' => __('registration.validation.phone_required'),
                'normalized' => null,
            ];
        }

        // Нормализация: удаляем все нецифровые символы кроме +
        $normalized = preg_replace('/[^\d+]/', '', $phone);

        // Edge case: только пробелы или пусто после нормализации
        if (empty($normalized)) {
            return [
                'valid' => false,
                'error' => __('registration.validation.phone_invalid'),
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
                'error' => __('registration.validation.phone_invalid'),
                'normalized' => null,
            ];
        }

        // Проверяем количество цифр (минимум 10 для международного номера)
        if (strlen($digitsOnly) < 10 || strlen($digitsOnly) > 15) {
            return [
                'valid' => false,
                'error' => __('registration.validation.phone_invalid'),
                'normalized' => null,
            ];
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
                'error' => __('registration.validation.email_required'),
                'normalized' => null,
            ];
        }

        // Edge case: только пробелы - trim
        $normalized = trim($email);
        
        if (empty($normalized)) {
            return [
                'valid' => false,
                'error' => __('registration.validation.email_required'),
                'normalized' => null,
            ];
        }

        // Валидация через фильтр PHP
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'error' => __('registration.validation.email_invalid'),
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

