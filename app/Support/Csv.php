<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class Csv
{
    /**
     * Streams rows as a UTF-8 CSV download without holding them all in memory.
     *
     * @param  iterable<array<string, mixed>>  $rows  associative rows; the first row's keys become the header
     */
    public static function download(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens it as UTF-8
            $headerWritten = false;
            foreach ($rows as $row) {
                if (! $headerWritten) {
                    fputcsv($out, array_keys($row), escape: '');
                    $headerWritten = true;
                }
                fputcsv($out, array_map([self::class, 'cell'], array_values($row)), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * Arrays are joined with " | ". Values starting with = + - @ (or tab/CR) are
     * prefixed with ' so spreadsheet apps don't run scraped text as a formula.
     */
    public static function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(' | ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
