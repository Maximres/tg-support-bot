<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\EntitiesToHtml;
use PHPUnit\Framework\TestCase;

class EntitiesToHtmlTest extends TestCase
{
    public function test_plain_text_is_escaped(): void
    {
        $this->assertSame('a &lt; b &amp; c &gt; d', EntitiesToHtml::convert('a < b & c > d', []));
        $this->assertSame('', EntitiesToHtml::convert(null, null));
    }

    public function test_hidden_text_link_is_kept(): void
    {
        $html = EntitiesToHtml::convert('Пишите в ЧАТ-ПОДДЕРЖКИ сегодня', [
            ['type' => 'text_link', 'offset' => 9, 'length' => 13, 'url' => 'https://t.me/Garetski_Rent_Bot'],
        ]);

        $this->assertSame('Пишите в <a href="https://t.me/Garetski_Rent_Bot">ЧАТ-ПОДДЕРЖКИ</a> сегодня', $html);
    }

    public function test_offsets_are_counted_in_utf16_units(): void
    {
        // «💬» занимает две единицы UTF-16, поэтому слово «ЧАТОМ» начинается со смещения 3, а не 2
        $html = EntitiesToHtml::convert('💬 ЧАТОМ пишите', [
            ['type' => 'text_link', 'offset' => 3, 'length' => 5, 'url' => 'https://example.com'],
        ]);

        $this->assertSame('💬 <a href="https://example.com">ЧАТОМ</a> пишите', $html);
    }

    public function test_nested_and_adjacent_formatting(): void
    {
        $html = EntitiesToHtml::convert('Важно: срочно и точно', [
            ['type' => 'bold', 'offset' => 0, 'length' => 13],
            ['type' => 'italic', 'offset' => 7, 'length' => 6],
            ['type' => 'underline', 'offset' => 16, 'length' => 4],
        ]);

        $this->assertSame('<b>Важно: <i>срочно</i></b> и <u>точн</u>о', $html);
    }

    public function test_code_pre_and_special_characters(): void
    {
        $html = EntitiesToHtml::convert('x=1<2 и блок', [
            ['type' => 'code', 'offset' => 0, 'length' => 5],
            ['type' => 'pre', 'offset' => 8, 'length' => 4, 'language' => 'php'],
        ]);

        $this->assertSame('<code>x=1&lt;2</code> и <pre><code class="language-php">блок</code></pre>', $html);
    }

    public function test_link_url_attribute_is_escaped(): void
    {
        $html = EntitiesToHtml::convert('ссылка', [
            ['type' => 'text_link', 'offset' => 0, 'length' => 6, 'url' => 'https://example.com/?a=1&b="2"'],
        ]);

        $this->assertSame('<a href="https://example.com/?a=1&amp;b=&quot;2&quot;">ссылка</a>', $html);
    }

    public function test_plain_url_entities_and_overlaps_do_not_break_output(): void
    {
        $html = EntitiesToHtml::convert('см. https://example.com тут', [
            ['type' => 'url', 'offset' => 4, 'length' => 19],
            ['type' => 'bold', 'offset' => 0, 'length' => 10],
            ['type' => 'italic', 'offset' => 6, 'length' => 10],
        ]);

        // Пересекающиеся (не вложенные) entity пропускаются: текст остаётся целым, теги закрыты
        $this->assertSame('см. https://example.com тут', strip_tags($html));
        $this->assertSame(substr_count($html, '<b>'), substr_count($html, '</b>'));
        $this->assertSame(substr_count($html, '<i>'), substr_count($html, '</i>'));
    }

    public function test_invalid_entities_are_ignored(): void
    {
        $this->assertSame('abc', EntitiesToHtml::convert('abc', [
            ['type' => 'bold', 'offset' => 10, 'length' => 5],
            ['type' => 'bold', 'offset' => 0, 'length' => 0],
        ]));
    }

    public function test_length_is_measured_in_utf16_units(): void
    {
        $this->assertSame(5, EntitiesToHtml::length('ab💬c'));
        $this->assertSame(0, EntitiesToHtml::length(''));
    }

    public function test_long_text_is_split_without_losing_formatting(): void
    {
        $paragraph = str_repeat('слово ', 100); // 600 символов
        $text = $paragraph . "\n\n" . $paragraph . "\n\n" . $paragraph . "\n\n" . 'ссылка';
        $linkOffset = EntitiesToHtml::length($text) - 6;

        $chunks = EntitiesToHtml::convertInChunks($text, [
            ['type' => 'text_link', 'offset' => $linkOffset, 'length' => 6, 'url' => 'https://example.com'],
        ], 1000);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1000, EntitiesToHtml::length(strip_tags($chunk)));
        }

        $joined = implode(' ', $chunks);
        $this->assertStringContainsString('<a href="https://example.com">ссылка</a>', $joined);
        $this->assertSame(
            preg_replace('/\s+/u', '', $text),
            preg_replace('/\s+/u', '', strip_tags($joined))
        );
    }

    public function test_short_text_gives_a_single_chunk(): void
    {
        $this->assertSame(['Привет'], EntitiesToHtml::convertInChunks('Привет', []));
        $this->assertSame([], EntitiesToHtml::convertInChunks('', []));
    }
}
