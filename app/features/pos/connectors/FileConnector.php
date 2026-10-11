<?php
declare(strict_types=1);

/**
 * The `file` connector (pos-and-depletion.md §2): an export read through a sales_file_mappings row — by upload (the handler), from the
 * intake mailbox (the worker) or over SFTP (the worker's pull). read() reads CSV or XLSX; pull() lists the SFTP directory and fetches
 * the newest files not yet seen (`settings.seen_files[]`, ≤ 200, by name), matching `settings.sftp.pattern` (a glob, `*` by default).
 * SFTP goes through curl's sftp:// (a password or a key from the opened credential); outside production a `settings.sftp.local_dir`
 * stands in for the server (the proofs' fixture — pos.md DECISION).
 */
final class RiPosFileConnector implements RiPosConnector
{
    /** A file through a mapping: ['headers', 'checks', 'bad', 'missing', 'rows', 'kind' csv|xlsx]. */
    public static function read(string $path, array $mapping, string $tz, string $cut, ?string $nameForType = null): array
    {
        $isXlsx = RiPosXlsx::looks_like($path) || ($nameForType !== null && str_ends_with(strtolower($nameForType), '.xlsx'));
        if ($isXlsx && !RiPosXlsx::looks_like($path)) {
            throw new DomainException('That file is named .xlsx but is not one — export as CSV.');
        }
        $table = $isXlsx ? RiPosXlsx::read($path) : RiPosCsv::read($path, ($mapping['delimiter'] ?? '') ?: null);
        $out = RiPosNormalizer::from_rows($table, $mapping, $tz, $cut);
        return $out + ['headers' => $table['headers'], 'kind' => $isXlsx ? 'xlsx' : 'csv', 'table' => $table];
    }

    public function probe(array $source): array
    {
        $intake = (string) ($source['settings']['intake'] ?? 'upload');
        if ($intake !== 'sftp') {
            return ['ok' => true, 'message' => $intake === 'email' ? 'Fed by email to the intake mailbox — nothing to probe.' : 'Fed by upload — drop a file.', 'detail' => ['intake' => $intake]];
        }
        try {
            $files = $this->list($source);
            return ['ok' => true, 'message' => count($files) . ' file' . (count($files) === 1 ? '' : 's') . ' in the folder; ' . count($this->unseen($source, $files)) . ' not imported yet.', 'detail' => ['files' => array_slice($files, 0, 20)]];
        } catch (RiPosPullError $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'detail' => []];
        }
    }

    public function pull(array $source, DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $intake = (string) ($source['settings']['intake'] ?? 'upload');
        if ($intake !== 'sftp') {
            throw new DomainException('This source is fed by ' . ($intake === 'email' ? 'email' : 'upload') . ' — drop a file.');
        }
        $files = $this->unseen($source, $this->list($source));
        $got = [];
        foreach ($files as $name) {
            $got[] = ['name' => $name, 'path' => $this->fetch($source, $name)];
        }
        $seen = array_slice(array_values(array_unique(array_merge((array) ($source['settings']['seen_files'] ?? []), $files))), -200);
        return ['checks' => [], 'files' => $got, 'bad' => [], 'next_state' => ['seen_files' => $seen]];
    }

    /** The file names in the SFTP folder matching the pattern, sorted by name (oldest first by the exports' dated names). */
    private function list(array $source): array
    {
        $sftp = (array) ($source['settings']['sftp'] ?? []);
        $pattern = (string) (($sftp['pattern'] ?? '') ?: '*');
        $local = (string) ($sftp['local_dir'] ?? '');
        if ($local !== '' && !app_is_prod()) {
            if (!is_dir($local)) {
                throw new RiPosPullError('The SFTP folder is not there.');
            }
            $names = array_values(array_filter(scandir($local) ?: [], static fn (string $n): bool => is_file($local . '/' . $n) && fnmatch($pattern, $n)));
            sort($names);
            return $names;
        }
        $raw = $this->curl($source, rtrim((string) ($sftp['path'] ?? '/'), '/') . '/', true);
        $names = array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn (string $n): bool => $n !== '' && $n !== '.' && $n !== '..' && fnmatch($pattern, $n)));
        sort($names);
        return $names;
    }

    private function unseen(array $source, array $files): array
    {
        $seen = (array) ($source['settings']['seen_files'] ?? []);
        return array_values(array_diff($files, $seen));
    }

    private function fetch(array $source, string $name): string
    {
        $sftp = (array) ($source['settings']['sftp'] ?? []);
        $tmp = tempnam(sys_get_temp_dir(), 'ripos');
        $local = (string) ($sftp['local_dir'] ?? '');
        if ($local !== '' && !app_is_prod()) {
            copy($local . '/' . $name, $tmp);
            return $tmp;
        }
        file_put_contents($tmp, $this->curl($source, rtrim((string) ($sftp['path'] ?? '/'), '/') . '/' . rawurlencode($name), false));
        return $tmp;
    }

    private function curl(array $source, string $path, bool $listOnly): string
    {
        $sftp = (array) ($source['settings']['sftp'] ?? []);
        $host = (string) ($sftp['host'] ?? '');
        if ($host === '') {
            throw new RiPosPullError('The SFTP host is not set.');
        }
        $cred = $source['credential'] ?? null;
        if ($cred === null) {
            throw new RiPosPullError('The SFTP source has no credential — set one.');
        }
        $ch = curl_init('sftp://' . $host . ':' . (int) ($sftp['port'] ?? 22) . $path);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => (int) ($source['timeout'] ?? 20), CURLOPT_DIRLISTONLY => $listOnly,
                CURLOPT_USERNAME => (string) ($cred['username'] ?? ($sftp['user'] ?? ''))];
        $keyFile = null;
        if (($cred['kind'] ?? '') === 'sftp_key') {
            $keyFile = tempnam(sys_get_temp_dir(), 'rikey');
            chmod($keyFile, 0600);
            file_put_contents($keyFile, (string) ($cred['private_key'] ?? ''));
            $opt[CURLOPT_SSH_PRIVATE_KEYFILE] = $keyFile;
            $opt[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PUBLICKEY;
        } else {
            $opt[CURLOPT_PASSWORD] = (string) ($cred['password'] ?? '');
            $opt[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PASSWORD;
        }
        curl_setopt_array($ch, $opt);
        $out = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($keyFile !== null) {
            @unlink($keyFile);
        }
        if ($err !== 0 || $out === false) {
            throw new RiPosPullError('The SFTP server did not answer (curl ' . $err . ').');
        }
        return (string) $out;
    }
}
