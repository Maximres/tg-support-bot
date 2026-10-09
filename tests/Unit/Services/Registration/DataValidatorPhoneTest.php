<?php

namespace Tests\Unit\Services\Registration;

use App\Services\Registration\DataValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DataValidatorPhoneTest extends TestCase
{
    public static function validPhones(): array
    {
        return [
            'Беларусь' => ['+375291234567', '+375291234567'],
            'Беларусь с пробелами' => ['+375 (29) 123-45-67', '+375291234567'],
            'без плюса' => ['375291234567', '+375291234567'],
            'Россия' => ['+79123456789', '+79123456789'],
            'Украина' => ['+380501234567', '+380501234567'],
            'Польша' => ['+48123456789', '+48123456789'],
            'США' => ['+12025550123', '+12025550123'],
        ];
    }

    public static function invalidPhones(): array
    {
        return [
            'Беларусь, лишняя цифра' => ['+375292111511111'],
            'Беларусь, не хватает цифры' => ['+37529123456'],
            'Россия, лишняя цифра' => ['+791234567890'],
            'Украина, не хватает' => ['+38050123456'],
            'слишком коротко' => ['+12345'],
            'слишком длинно' => ['+1234567890123456'],
            'буквы' => ['abc'],
            'пусто' => [''],
        ];
    }

    #[DataProvider('validPhones')]
    public function test_accepts_valid_phones(string $input, string $expected): void
    {
        $result = (new DataValidator())->validatePhone($input);

        $this->assertTrue($result['valid'], $input);
        $this->assertSame($expected, $result['normalized']);
    }

    #[DataProvider('invalidPhones')]
    public function test_rejects_wrong_length_or_garbage(string $input): void
    {
        $result = (new DataValidator())->validatePhone($input);

        $this->assertFalse($result['valid'], $input);
        $this->assertNull($result['normalized']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_wrong_length_message_names_the_expected_length(): void
    {
        $result = (new DataValidator())->validatePhone('+375292111511111');

        $this->assertStringContainsString('12 цифр', $result['error']);
        $this->assertStringContainsString('+375291234567', $result['error']);
    }
}
