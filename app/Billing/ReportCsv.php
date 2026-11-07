<?php

namespace App\Billing;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsv
{
    public static function download(string $filename, callable $rows, string $delimiter = ','): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $delimiter) {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open report output.');
            }
            $write = function (array $row) use ($handle, $delimiter) {
                // Spreadsheet formulas must remain literal data in financial exports.
                $row = array_map(fn ($value) => is_string($value) && preg_match('/^\s*[=+@\t\r-]/u', $value) && ! is_numeric($value) ? "'".$value : $value, $row);
                if (fputcsv($handle, $row, $delimiter, '"', '', "\r\n") === false) {
                    throw new RuntimeException('Unable to write the report CSV.');
                }
            };
            try {
                $rows($write);
            } finally {
                fclose($handle);
            }
        }, $filename, ['Content-Type' => ($delimiter === "\t" ? 'text/tab-separated-values' : 'text/csv').'; charset=UTF-8',
            'Cache-Control' => 'private, no-store']);
    }
}
