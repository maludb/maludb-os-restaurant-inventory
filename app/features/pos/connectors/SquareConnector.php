<?php
declare(strict_types=1);

/**
 * The `square` connector (pos-and-depletion.md §3): POST {base}/v2/orders/search for the source's location, completed orders closed in
 * the window, sorted by close time, 500 a page, following `cursor`; `Square-Version` pinned. Per order: `id` → external_id, `closed_at`,
 * line items (`uid` → external_line_id, the catalog variation id → plu, `name` + `variation_name` → name, `quantity`, base price and
 * total ÷ 100, modifiers by catalog id), a line returned (`returns[].return_line_items[].source_line_item_uid`) → voided, a line at
 * zero total with a base price → comp. Tenders are not read (money is the ledger's). The base URL: the source's, else
 * RI_SQUARE_BASE_URL, else https://connect.squareup.com. A token is sent in the header and nowhere else.
 */
final class RiPosSquareConnector implements RiPosConnector
{
    public const VERSION = '2025-01-23';

    public function probe(array $source): array
    {
        try {
            $page = $this->search($source, (new DateTimeImmutable('-1 day')), new DateTimeImmutable(), null, 1);
            return ['ok' => true, 'message' => 'Square answered for location ' . ($source['settings']['location_id'] ?? '?') . '.', 'detail' => ['orders_last_day' => count((array) ($page['orders'] ?? []))]];
        } catch (RiPosPullError $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'detail' => ['status' => $e->status]];
        }
    }

    public function pull(array $source, DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $checks = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->search($source, $since, $until, $cursor, 500);
            foreach ((array) ($page['orders'] ?? []) as $o) {
                $checks[] = $this->check($o, (string) $source['tz'], (string) $source['cut']);
            }
            $cursor = isset($page['cursor']) && $page['cursor'] !== '' ? (string) $page['cursor'] : null;
            $pages++;
        } while ($cursor !== null && $pages < 50);
        return ['checks' => $checks, 'files' => [], 'bad' => [], 'next_state' => ['pages' => $pages]];
    }

    private function check(array $o, string $tz, string $cut): array
    {
        $returned = [];
        foreach ((array) ($o['returns'] ?? []) as $r) {
            foreach ((array) ($r['return_line_items'] ?? []) as $rl) {
                if (isset($rl['source_line_item_uid'])) {
                    $returned[(string) $rl['source_line_item_uid']] = true;
                }
            }
        }
        $lines = [];
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $uid = (string) ($li['uid'] ?? '');
            $base = isset($li['base_price_money']['amount']) ? ((int) $li['base_price_money']['amount']) / 100 : null;
            $total = isset($li['total_money']['amount']) ? ((int) $li['total_money']['amount']) / 100 : null;
            $qty = (float) ($li['quantity'] ?? 1);
            $mods = [];
            foreach ((array) ($li['modifiers'] ?? []) as $m) {
                $mods[] = ['code' => (string) ($m['catalog_object_id'] ?? ($m['name'] ?? '')), 'name' => (string) ($m['name'] ?? ''), 'qty' => (float) ($m['quantity'] ?? 1)];
            }
            $name = trim((string) ($li['name'] ?? '') . (isset($li['variation_name']) && $li['variation_name'] !== '' && $li['variation_name'] !== 'Regular' ? ' (' . $li['variation_name'] . ')' : ''));
            $comp = $total !== null && $total == 0.0 && $base !== null && $base > 0;
            $lines[] = ['external_line_id' => $uid, 'plu' => (string) ($li['catalog_object_id'] ?? $name), 'name' => $name, 'qty' => $qty, 'unit_price' => $base,
                        'line_total' => $total, 'gross' => $base === null ? $total : round($base * $qty, 2), 'voided' => isset($returned[$uid]), 'comp' => $comp, 'modifiers' => $mods];
        }
        return RiPosNormalizer::check(['external_id' => (string) $o['id'], 'business_date' => null, 'closed_at' => isset($o['closed_at']) ? (new DateTimeImmutable((string) $o['closed_at']))->format(DATE_ATOM) : null,
                                       'covers' => null, 'tax' => isset($o['total_tax_money']['amount']) ? ((int) $o['total_tax_money']['amount']) / 100 : null,
                                       'total' => isset($o['total_money']['amount']) ? ((int) $o['total_money']['amount']) / 100 : null, 'lines' => $lines], $tz, $cut);
    }

    private function search(array $source, DateTimeImmutable $since, DateTimeImmutable $until, ?string $cursor, int $limit): array
    {
        $token = (string) ($source['credential']['token'] ?? ($source['credential']['api_key'] ?? ''));
        if ($token === '') {
            throw new RiPosPullError('The Square source has no token — set its credential.');
        }
        $location = (string) ($source['settings']['location_id'] ?? '');
        if ($location === '') {
            throw new RiPosPullError('The Square location is not set.');
        }
        $base = rtrim((string) (($source['base_url'] ?? '') ?: (env('RI_SQUARE_BASE_URL', '') ?: 'https://connect.squareup.com')), '/');
        $body = ['location_ids' => [$location], 'limit' => $limit, 'return_entries' => false, 'query' => ['filter' => ['state_filter' => ['states' => ['COMPLETED']],
                 'date_time_filter' => ['closed_at' => ['start_at' => $since->format(DATE_ATOM), 'end_at' => $until->format(DATE_ATOM)]]], 'sort' => ['sort_field' => 'CLOSED_AT', 'sort_order' => 'ASC']]];
        if ($cursor !== null) {
            $body['cursor'] = $cursor;
        }
        $ch = curl_init($base . '/v2/orders/search');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => (int) ($source['timeout'] ?? 20),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Square-Version: ' . self::VERSION, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES)]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $raw === false) {
            throw new RiPosPullError('Square did not answer (network error ' . $errno . ').');
        }
        $headers = substr((string) $raw, 0, $hs);
        $json = json_decode(substr((string) $raw, $hs), true);
        if ($status === 401 || $status === 403) {
            throw new RiPosPullError('Square refused the token (HTTP ' . $status . ').', $status);
        }
        if ($status === 429) {
            throw new RiPosPullError('Square asked us to slow down (HTTP 429).', 429, preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m) ? (int) $m[1] : null);
        }
        if ($status !== 200 || !is_array($json)) {
            throw new RiPosPullError('Square answered HTTP ' . $status . '.', $status);
        }
        return $json;
    }
}
