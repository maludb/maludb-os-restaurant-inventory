<?php
declare(strict_types=1);

/**
 * The connector interface (pos-and-depletion.md §3; pos.md "The connector interface"). A connector only READS: it never touches the
 * database and never opens a credential — its caller (app/features/pos/import.php pos_connector_source()) hands it the source with
 * `settings` decoded, `credential` opened (or null), `base_url`, `timeout`, the site's `tz` and `cut`. The schema resolves PLUs and
 * depletes; the worker drives; the screens ask.
 *   probe($source)                → ['ok' => bool, 'message' => string, 'detail' => array]
 *   pull($source, $since, $until) → ['checks' => list<normalized check>, 'files' => list<['name', 'path']>, 'bad' => list, 'next_state' => array]
 * A `file` source answers files (the caller reads each through its mapping and keeps it on the pull); `square` answers checks.
 * A failure is a RiPosPullError carrying the HTTP status when there was one (401 → the token refused, 429 → wait).
 */

final class RiPosPullError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

interface RiPosConnector
{
    public function probe(array $source): array;

    public function pull(array $source, DateTimeImmutable $since, DateTimeImmutable $until): array;
}

require_once __DIR__ . '/Csv.php';
require_once __DIR__ . '/Xlsx.php';
require_once __DIR__ . '/Normalizer.php';
require_once __DIR__ . '/FileConnector.php';
require_once __DIR__ . '/SquareConnector.php';
require_once __DIR__ . '/ManualConnector.php';

/** The connectors of version 1 (ri_connectors() admits toast, clover and lightspeed for Extended — not built). */
function ri_pos_connectors(): array
{
    return ['file' => 'A file — upload, email or SFTP', 'square' => 'Square (Orders API)', 'manual' => 'Manual — a person uploads'];
}

/** The Extended connectors the form lists disabled. */
const RI_POS_EXTENDED = ['toast' => 'Toast', 'clover' => 'Clover', 'lightspeed' => 'Lightspeed'];

function ri_pos_connector(string $key): RiPosConnector
{
    return match ($key) {
        'file' => new RiPosFileConnector(),
        'square' => new RiPosSquareConnector(),
        'manual' => new RiPosManualConnector(),
        default => throw new DomainException(isset(RI_POS_EXTENDED[$key]) ? RI_POS_EXTENDED[$key] . ' is an Extended connector — not built in version 1. Use its file export with a mapping.' : 'There is no connector called ' . $key . '.'),
    };
}
