<?php

namespace App\Billing;

final class DocumentNotes
{
    public static function chunks(?string $notes): array
    {
        $chunks = [];
        $chunk = '';
        $length = $lines = 0;
        // Dompdf cannot split a table cell across pages, so bound each continuation row.
        foreach (mb_str_split($notes ?? '') as $character) {
            $chunk .= $character;
            $length++;
            if ($character === "\n" || $character === "\r") {
                $lines++;
            }
            if ($length >= 400 || $lines >= 12) {
                $tail = '';
                if ($length >= 400 && preg_match('/^(.*\s)(\S+)$/us', $chunk, $parts) === 1) {
                    $chunk = $parts[1];
                    $tail = $parts[2];
                }
                $chunks[] = $chunk;
                $chunk = $tail;
                $length = mb_strlen($tail);
                $lines = 0;
            }
        }
        if ($chunk !== '' || $chunks === []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }
}
