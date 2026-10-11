<?php
declare(strict_types=1);

/**
 * A file's rows through a mapping → normalized checks (pos-and-depletion.md §2.1–§2.2). One row per LINE; the check's header fields
 * repeat on each line and the first row's win. A mapping is {delimiter, date_format, columns: {field: header}, void_values,
 * comp_values, comp_rule, modifier_split}; headers match case-insensitively after trimming. Required: external_id, qty, a plu or an
 * item name, and a business date or a close time. A row that cannot be read is answered in `bad` with its reason — never imported,
 * never silently dropped. Every check also passes check() — the one validator a connector's output meets (Square's too):
 *   ['external_id', 'business_date' (Y-m-d), 'closed_at' (ISO 8601 with the zone or null), 'covers', 'subtotal', 'tax', 'total',
 *    'comps', 'voids', 'lines' => [['external_line_id', 'plu', 'name', 'qty', 'unit_price', 'line_total', 'voided', 'comp',
 *    'modifiers' => [['code', 'name', 'qty']]]]]
 * The business date: the file's, else the close time in the site's zone, a moment before the sales cut belonging to the day before.
 */
final class RiPosNormalizer
{
    public const FIELDS = ['external_id', 'business_date', 'closed_at', 'plu', 'item_name', 'qty', 'unit_price', 'line_total', 'voided', 'comp', 'modifiers', 'covers', 'external_line_id', 'tax', 'total'];
    public const REQUIRED = ['external_id', 'qty'];
    private const VOID_DEFAULT = ['yes', 'true', '1', 'y', 'void', 'voided'];
    private const COMP_DEFAULT = ['yes', 'true', '1', 'y', 'comp', 'comped'];

    /** The mapping's columns that the file does not have (a required field named but absent, or neither plu nor item_name, or no date at all). */
    public static function missing(array $headers, array $mapping): array
    {
        $have = array_map(static fn ($h): string => mb_strtolower(trim((string) $h)), $headers);
        $cols = (array) ($mapping['columns'] ?? []);
        $present = static fn (string $f): bool => isset($cols[$f]) && $cols[$f] !== null && $cols[$f] !== '' && in_array(mb_strtolower(trim((string) $cols[$f])), $have, true);
        $missing = [];
        foreach (self::REQUIRED as $f) {
            if (!$present($f)) {
                $missing[] = $f;
            }
        }
        if (!$present('plu') && !$present('item_name')) {
            $missing[] = 'plu';
        }
        if (!$present('business_date') && !$present('closed_at')) {
            $missing[] = 'business_date';
        }
        return $missing;
    }

    /** @return array{checks: list<array>, bad: list<array{row:int, reason:string}>, missing: list<string>, rows: int} */
    public static function from_rows(array $table, array $mapping, string $tz, string $cut): array
    {
        $missing = self::missing($table['headers'], $mapping);
        if ($missing !== []) {
            return ['checks' => [], 'bad' => [], 'missing' => $missing, 'rows' => count($table['rows'])];
        }
        $cols = [];
        foreach ((array) ($mapping['columns'] ?? []) as $f => $h) {
            if ($h !== null && $h !== '') {
                $cols[$f] = mb_strtolower(trim((string) $h));
            }
        }
        $voidWords = array_map('mb_strtolower', (array) ($mapping['void_values'] ?? self::VOID_DEFAULT));
        $compWords = array_map('mb_strtolower', (array) ($mapping['comp_values'] ?? self::COMP_DEFAULT));
        $fullDiscount = ($mapping['comp_rule'] ?? '') === 'full_discount';
        $split = (string) ($mapping['modifier_split'] ?? ';');
        $dateFmt = (string) ($mapping['date_format'] ?? '');
        $checks = [];
        $bad = [];
        foreach ($table['rows'] as $i => $row) {
            $r = [];
            foreach ($row as $h => $v) {
                $r[mb_strtolower(trim((string) $h))] = trim((string) $v);
            }
            $get = static fn (string $f): string => isset($cols[$f]) ? (string) ($r[$cols[$f]] ?? '') : '';
            $rowNo = $i + 2;                                                     // the header is row 1
            $ext = $get('external_id');
            if ($ext === '') {
                $bad[] = ['row' => $rowNo, 'reason' => 'no check id'];
                continue;
            }
            $qty = self::number($get('qty'));
            if ($qty === null || $qty <= 0) {
                $bad[] = ['row' => $rowNo, 'reason' => 'the quantity is not a positive number (' . mb_substr($get('qty'), 0, 20) . ')'];
                continue;
            }
            $name = $get('item_name');
            $plu = $get('plu') !== '' ? $get('plu') : $name;
            if ($plu === '') {
                $bad[] = ['row' => $rowNo, 'reason' => 'no PLU and no item name'];
                continue;
            }
            $bdRaw = $get('business_date');
            $bd = $bdRaw === '' ? null : self::date($bdRaw, $dateFmt);
            if ($bdRaw !== '' && $bd === null) {
                $bad[] = ['row' => $rowNo, 'reason' => 'the date "' . mb_substr($bdRaw, 0, 20) . '" does not read as ' . ($dateFmt ?: 'a date')];
                continue;
            }
            $closed = self::closed_at($get('closed_at'), $bd, $dateFmt, $tz, $cut);
            if ($bd === null) {
                if ($closed === null) {
                    $bad[] = ['row' => $rowNo, 'reason' => 'no date and no close time'];
                    continue;
                }
                $bd = self::business_date_of($closed, $tz, $cut);
            }
            $unit = self::number($get('unit_price'));
            $total = self::number($get('line_total'));
            $gross = $total ?? ($unit === null ? null : round($unit * $qty, 2));
            $unit ??= ($gross !== null && $qty > 0 ? round($gross / $qty, 4) : null);
            $voided = in_array(mb_strtolower($get('voided')), $voidWords, true);
            $compRaw = $get('comp');
            if ($fullDiscount) {
                $d = abs((float) (self::number($compRaw) ?? 0));
                $comp = $d > 0 && $gross !== null && $d >= abs($gross) - 0.005;
                $net = $gross === null ? null : round(max(abs($gross) - $d, 0), 2);
            } else {
                $comp = in_array(mb_strtolower($compRaw), $compWords, true);
                $net = $comp ? 0.0 : $gross;
            }
            $line = ['external_line_id' => $get('external_line_id'), 'plu' => mb_substr($plu, 0, 80), 'name' => $name !== '' ? mb_substr($name, 0, 200) : null, 'qty' => $qty,
                     'unit_price' => $unit, 'line_total' => $net, 'gross' => $gross, 'voided' => $voided, 'comp' => $comp && !$voided, 'modifiers' => self::modifiers($get('modifiers'), $split)];
            if (!isset($checks[$ext])) {
                $checks[$ext] = ['external_id' => mb_substr($ext, 0, 120), 'business_date' => $bd, 'closed_at' => $closed, 'covers' => null, 'tax' => self::number($get('tax')),
                                 'total' => self::number($get('total')), 'lines' => []];
            }
            $cv = self::number($get('covers'));
            if ($cv !== null && $cv >= 0) {
                $checks[$ext]['covers'] = max((int) $checks[$ext]['covers'], (int) $cv);
            }
            $checks[$ext]['lines'][] = $line;
        }
        $out = [];
        foreach ($checks as $c) {
            $out[] = self::check($c, $tz, $cut);
        }
        return ['checks' => $out, 'bad' => $bad, 'missing' => [], 'rows' => count($table['rows'])];
    }

    /** The one validator: defaults filled, the business date derived when absent, the money summed, line ids numbered. */
    public static function check(array $c, string $tz, string $cut): array
    {
        if (($c['external_id'] ?? '') === '') {
            throw new DomainException('A check without an id cannot be imported.');
        }
        if (($c['business_date'] ?? null) === null) {
            if (($c['closed_at'] ?? null) === null) {
                throw new DomainException('Check ' . $c['external_id'] . ' has no date and no close time.');
            }
            $c['business_date'] = self::business_date_of((string) $c['closed_at'], $tz, $cut);
        }
        $sub = 0.0;
        $comps = 0.0;
        $voids = 0.0;
        $n = 0;
        foreach ($c['lines'] as &$l) {
            $n++;
            $l['external_line_id'] = (string) (($l['external_line_id'] ?? '') !== '' ? $l['external_line_id'] : $n);
            $l['qty'] = (float) ($l['qty'] ?? 1) ?: 1.0;
            $l['voided'] = (bool) ($l['voided'] ?? false);
            $l['comp'] = (bool) ($l['comp'] ?? false) && !$l['voided'];
            $l['modifiers'] = array_values((array) ($l['modifiers'] ?? []));
            $gross = $l['gross'] ?? ($l['line_total'] ?? ($l['unit_price'] === null ? 0 : $l['unit_price'] * $l['qty']));
            unset($l['gross']);
            if ($l['voided']) {
                $voids += (float) $gross;
            } elseif ($l['comp']) {
                $comps += (float) $gross;
                $l['line_total'] = 0.0;
            } else {
                $sub += (float) ($l['line_total'] ?? 0);
            }
        }
        unset($l);
        $c['subtotal'] = round($sub, 2);
        $c['comps'] = round($comps, 2);
        $c['voids'] = round($voids, 2);
        $c['tax'] ??= null;
        $c['total'] ??= ($c['tax'] === null ? $c['subtotal'] : round($c['subtotal'] + (float) $c['tax'], 2));
        $c['covers'] ??= null;
        $c['closed_at'] ??= null;
        return $c;
    }

    /** A business date from a close time: the site's local calendar day, the day before when the local time is before the sales cut. */
    public static function business_date_of(string $closedAt, string $tz, string $cut): string
    {
        $t = (new DateTimeImmutable($closedAt))->setTimezone(new DateTimeZone($tz));
        return ($t->format('H:i:s') < substr($cut . ':00', 0, 8) ? $t->modify('-1 day') : $t)->format('Y-m-d');
    }

    /** A close time: a time alone joins the business date (a moment before the cut is the next calendar day); a full date-time is read in the site's zone. */
    public static function closed_at(string $v, ?string $bd, string $dateFmt, string $tz, string $cut): ?string
    {
        if ($v === '') {
            return null;
        }
        $zone = new DateTimeZone($tz);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?$/', $v, $m)) {
            if ($bd === null) {
                return null;
            }
            $h = (int) $m[1];
            if (isset($m[4]) && $m[4] !== '') {
                $h = $h % 12 + (strtolower($m[4]) === 'pm' ? 12 : 0);
            }
            $time = sprintf('%02d:%02d:%02d', $h, (int) $m[2], (int) ($m[3] ?? 0));
            $day = new DateTimeImmutable($bd . ' ' . $time, $zone);
            if ($time < substr($cut . ':00', 0, 8)) {
                $day = $day->modify('+1 day');
            }
            return $day->format(DATE_ATOM);
        }
        if (is_numeric($v) && (float) $v > 20000) {                               // an Excel serial date-time
            $secs = (int) round(((float) $v - 25569) * 86400);
            return (new DateTimeImmutable(gmdate('Y-m-d H:i:s', $secs), $zone))->format(DATE_ATOM);   // the serial is the site's wall-clock time
        }
        $php = self::php_format($dateFmt);
        foreach ($php === '' ? [] : [$php . ' H:i:s', $php . ' H:i', $php . ' g:i A', $php . ' g:i:s A', $php . ' h:i A'] as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $v, $zone);
            if ($d !== false) {
                return $d->format(DATE_ATOM);
            }
        }
        try {
            return (new DateTimeImmutable($v, $zone))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /** A date in the mapping's format (YYYY-MM-DD, MM/DD/YYYY, DD.MM.YYYY…), an Excel serial, or ISO; null when it does not read. */
    public static function date(string $v, string $fmt): ?string
    {
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
        }
        $php = self::php_format($fmt);
        if ($php !== '') {
            $d = DateTimeImmutable::createFromFormat('!' . $php, $v);
            if ($d !== false && $d->format($php) === $v) {
                return $d->format('Y-m-d');
            }
            $d = DateTimeImmutable::createFromFormat('!' . str_replace(['m', 'd'], ['n', 'j'], $php), $v);    // 1/5/2026 for MM/DD/YYYY
            if ($d !== false) {
                return $d->format('Y-m-d');
            }
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        return null;
    }

    public static function php_format(string $fmt): string
    {
        return $fmt === '' ? '' : strtr($fmt, ['YYYY' => 'Y', 'YY' => 'y', 'MM' => 'm', 'DD' => 'd']);
    }

    /** "$1,234.50" → 1234.5; "(3.00)" → -3; "" → null. */
    public static function number(string $v): ?float
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $neg = str_starts_with($v, '(') && str_ends_with($v, ')');
        $v = preg_replace('/[^0-9.\-]/', '', $v) ?? '';
        if ($v === '' || !is_numeric($v)) {
            return null;
        }
        return $neg ? -(float) $v : (float) $v;
    }

    /** A JSON list, or tokens split by the mapping's separator: "XCHK:2", "NOTOM", "Extra chicken". */
    public static function modifiers(string $v, string $split): array
    {
        $v = trim($v);
        if ($v === '') {
            return [];
        }
        if ($v[0] === '[') {
            $j = json_decode($v, true);
            if (is_array($j)) {
                $out = [];
                foreach ($j as $m) {
                    $code = trim((string) (is_array($m) ? ($m['code'] ?? $m['name'] ?? '') : $m));
                    if ($code !== '') {
                        $out[] = ['code' => mb_substr($code, 0, 80), 'name' => is_array($m) ? (string) ($m['name'] ?? $code) : $code, 'qty' => is_array($m) ? (float) ($m['qty'] ?? 1) : 1.0];
                    }
                }
                return $out;
            }
        }
        $out = [];
        foreach (explode($split === '' ? ';' : $split, $v) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            $qty = 1.0;
            if (preg_match('/^(.+?)\s*[:x×]\s*(\d+(?:\.\d+)?)$/u', $tok, $m)) {
                $tok = trim($m[1]);
                $qty = (float) $m[2];
            }
            $out[] = ['code' => mb_substr($tok, 0, 80), 'name' => $tok, 'qty' => $qty];
        }
        return $out;
    }
}
