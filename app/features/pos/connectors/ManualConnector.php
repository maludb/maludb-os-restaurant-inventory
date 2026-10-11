<?php
declare(strict_types=1);

/** The `manual` connector: nothing to pull — a person uploads a file (pos.md: "a new manual source" from the upload screen is a `file` source with intake upload). */
final class RiPosManualConnector implements RiPosConnector
{
    public function probe(array $source): array
    {
        return ['ok' => true, 'message' => 'A manual source — a person uploads its files.', 'detail' => []];
    }

    public function pull(array $source, DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        throw new DomainException('This source is fed by upload — drop a file.');
    }
}
