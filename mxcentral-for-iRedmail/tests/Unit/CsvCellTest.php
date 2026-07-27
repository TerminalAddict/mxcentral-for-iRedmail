<?php

namespace Tests\Unit;

use App\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CsvCellTest extends TestCase
{
    #[DataProvider('dangerousCells')]
    public function test_formula_leading_cells_are_neutralized(string $input): void
    {
        $this->assertSame("'".$input, CsvCell::safe($input));
    }

    public static function dangerousCells(): array
    {
        return [
            'equals' => ['=HYPERLINK("https://attacker.invalid")'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at' => ['@SUM(1,2)'],
            'tab' => ["\t=cmd"],
            'crlf' => ["\r\n+cmd"],
            'unicode-control' => ["\u{200E}@cmd"],
        ];
    }

    public function test_normal_multiline_and_unicode_text_is_preserved(): void
    {
        $this->assertSame("Māori\nName", CsvCell::safe("Māori\nName"));
        $this->assertSame('ordinary@example.com', CsvCell::safe('ordinary@example.com'));
    }
}
