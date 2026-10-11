<?php
declare(strict_types=1);

/**
 * A POS export read as rows of header → value (pos.md "Files"): the BOM stripped, UTF-16 and Windows-1252 converted to UTF-8, the
 * delimiter sniffed from the header line when the mapping does not name one (, ; tab |), blank lines skipped, every row keyed by its
 * header TRIMMED (the mapping matches case-insensitively — RiPosNormalizer). No library.
 */
final class RiPosCsv
{
    /** @return array{headers: list<string>, rows: list<array<string,string>>, delimiter: string} */
    public static function read(string $path, ?string $delimiter = null): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new DomainException('The file could not be read.');
        }
        return self::parse($raw, $delimiter);
    }

    public static function parse(string $raw, ?string $delimiter = null): array
    {
        $raw = self::utf8($raw);
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $firstLine = strtok($raw, "\n") ?: '';
        $delimiter = ($delimiter === null || $delimiter === '') ? self::sniff($firstLine) : ($delimiter === '\t' ? "\t" : $delimiter);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $cells = array_map(static fn ($c): string => trim((string) $c), $cells);
            if ($headers === null) {
                $headers = $cells;
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($fh);
        if ($headers === null) {
            throw new DomainException('The file holds no header row.');
        }
        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    public static function sniff(string $line): string
    {
        $best = ',';
        $n = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $c = substr_count($line, $d);
            if ($c > $n) {
                $n = $c;
                $best = $d;
            }
        }
        return $best;
    }

    public static function utf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }
        if (str_starts_with($raw, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        return $raw;
    }
}
