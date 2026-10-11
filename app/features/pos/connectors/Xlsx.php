<?php
declare(strict_types=1);

/**
 * A minimal XLSX reader (pos.md "Files" — no library): the FIRST worksheet of the workbook, its shared strings, inline strings,
 * numbers and booleans; the first non-empty row is the header. A date a POS wrote as an Excel serial number stays a number here — the
 * normalizer reads a serial (RiPosNormalizer::date()). Rows keyed by the trimmed header, as RiPosCsv answers.
 */
final class RiPosXlsx
{
    public static function looks_like(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        $sig = $fh ? (string) fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        return $sig === "PK\x03\x04";
    }

    /** @return array{headers: list<string>, rows: list<array<string,string>>, delimiter: string} */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DomainException('That XLSX file could not be opened — export as CSV.');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = self::xml($ss);
            foreach ($x->si as $si) {
                $shared[] = self::text($si);
            }
        }
        $sheetPath = self::first_sheet($zip);
        $sheet = $sheetPath === null ? false : $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheet === false) {
            throw new DomainException('That XLSX file holds no worksheet — export as CSV.');
        }
        $x = self::xml($sheet);
        $grid = [];
        foreach ($x->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::col_index(preg_replace('/\d+/', '', $ref) ?: 'A');
                $t = (string) $c['t'];
                $v = match ($t) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v,
                };
                $cells[$col] = trim($v);
            }
            if ($cells !== [] && implode('', $cells) !== '') {
                $grid[] = $cells;
            }
        }
        if ($grid === []) {
            throw new DomainException('The file holds no header row.');
        }
        $headerCells = array_shift($grid);
        $width = max(array_keys($headerCells)) + 1;
        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $headers[$i] = $headerCells[$i] ?? '';
        }
        $rows = [];
        foreach ($grid as $cells) {
            $r = [];
            foreach ($headers as $i => $h) {
                $r[$h] = $cells[$i] ?? '';
            }
            $rows[] = $r;
        }
        return ['headers' => array_values($headers), 'rows' => $rows, 'delimiter' => 'xlsx'];
    }

    private static function first_sheet(ZipArchive $zip): ?string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $first = $w->sheets->sheet[0] ?? null;
            if ($first !== null) {
                $rid = (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                foreach (self::xml($rels)->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }

    private static function xml(string $s): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new DomainException('That XLSX file is damaged — export as CSV.');
        }
        return $x;
    }

    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            $s = (string) $node->t;
            foreach ($node->r ?? [] as $r) {
                $s .= (string) $r->t;
            }
            return $s;
        }
        $s = '';
        foreach ($node->r ?? [] as $r) {
            $s .= (string) $r->t;
        }
        return $s;
    }

    private static function col_index(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Write a one-sheet XLSX of rows (header first) — the proofs' twin of a CSV fixture; inline strings, numbers as numbers. */
    public static function write(string $path, array $table): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sales" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $rowsXml = '';
        foreach (array_values($table) as $ri => $row) {
            $rowsXml .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $v) {
                $ref = chr(65 + $ci) . ($ri + 1);
                $v = (string) $v;
                $rowsXml .= ($ri > 0 && is_numeric($v) && !preg_match('/^0\d/', $v))
                    ? '<c r="' . $ref . '"><v>' . $v . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($v, ENT_XML1) . '</t></is></c>';
            }
            $rowsXml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rowsXml . '</sheetData></worksheet>');
        $zip->close();
    }
}
