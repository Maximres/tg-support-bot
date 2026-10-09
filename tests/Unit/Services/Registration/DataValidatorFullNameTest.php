<?php

namespace Tests\Unit\Services\Registration;

use App\Services\Registration\DataValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DataValidatorFullNameTest extends TestCase
{
    public static function validNames(): array
    {
        return [
            'полное ФИО' => ['Иванов Иван Иванович'],
            'двойная фамилия' => ['Петров-Водкин Кузьма Сергеевич'],
            'апостроф' => ["О'Нил Шон Патрик"],
            'латиница' => ['Smith John Henry'],
            'лишние пробелы' => ['  Булгач   Максим   Юрьевич  '],
        ];
    }

    public static function invalidNames(): array
    {
        return [
            'цифры' => ['123'],
            'одно слово' => ['Максим'],
            'два слова' => ['Иванов Иван'],
            'четыре слова' => ['Иванов Иван Иванович Оглы'],
            'новый пользователь' => ['Новый Пользователь'],
            'цифры внутри' => ['Иванов 2 Иван'],
            'спецсимволы' => ['!!! ???'],
            'эмодзи' => ['Иван 😀'],
            'пустая строка' => [''],
            'пробелы' => ['    '],
            'слишком коротко' => ['Я'],
        ];
    }

    #[DataProvider('validNames')]
    public function test_accepts_real_names(string $name): void
    {
        $result = (new DataValidator())->validateFullName($name);

        $this->assertTrue($result['valid'], $name);
        $this->assertSame(trim($name), $result['normalized'] === null ? null : $result['normalized']);
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_non_names(string $name): void
    {
        $result = (new DataValidator())->validateFullName($name);

        $this->assertFalse($result['valid'], $name);
        $this->assertNull($result['normalized']);
        $this->assertNotEmpty($result['error']);
    }
}
