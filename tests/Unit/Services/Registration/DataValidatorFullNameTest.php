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
            'фамилия и имя' => ['Иванов Иван'],
            'полное ФИО' => ['Иванов Иван Иванович'],
            'двойная фамилия' => ['Петров-Водкин Кузьма Сергеевич'],
            'апостроф' => ["О'Нил Шон"],
            'латиница' => ['Smith John'],
            'лишние пробелы' => ['  Булгач   Максим  '],
        ];
    }

    public static function invalidNames(): array
    {
        return [
            'цифры' => ['123'],
            'одно слово' => ['Максим'],
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
