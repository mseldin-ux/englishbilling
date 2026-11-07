<?php

namespace Tests\Unit;

use App\Billing\DocumentNotes;
use PHPUnit\Framework\TestCase;

class DocumentNotesTest extends TestCase
{
    public function test_continuation_rows_preserve_all_text_and_bound_line_and_character_counts(): void
    {
        foreach ([null, '', str_repeat("Accented \u{00e9} text ", 600), str_repeat("One line\n", 100)] as $notes) {
            $chunks = DocumentNotes::chunks($notes);
            $this->assertSame($notes ?? '', implode('', $chunks));
            foreach ($chunks as $chunk) {
                $this->assertLessThanOrEqual(400, mb_strlen($chunk));
                $this->assertLessThanOrEqual(12, substr_count($chunk, "\n"));
            }
        }
    }
}
