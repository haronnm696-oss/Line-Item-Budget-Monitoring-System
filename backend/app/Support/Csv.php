<?php

namespace App\Support;

/**
 * The one CSV writer for every export (project reports and the Program
 * Leader projects list): UTF-8 with a BOM so Excel shows ₱ and accents,
 * CRLF line ends, and RFC 4180 quoting.
 */
class Csv
{
    /** @param array<int, array<int, mixed>> $rows */
    public static function render(array $rows): string
    {
        $lines = array_map(fn (array $row) => implode(',', array_map([self::class, 'cell'], $row)), $rows);

        return "\xEF\xBB\xBF" . implode("\r\n", $lines);
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = (string) $value;

        if (str_contains($string, ',') || str_contains($string, '"') || str_contains($string, "\n") || str_contains($string, "\r")) {
            return '"' . str_replace('"', '""', $string) . '"';
        }

        return $string;
    }

    /** Headers for a CSV download. */
    public static function headers(string $filename): array
    {
        return [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache',
        ];
    }
}
