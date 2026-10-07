<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use Pulsar\Api\Api;

/**
 * Something that contributes entries to a backup.
 *
 * A source is deliberately narrow: it names itself, says in one clause what it
 * covers, and yields entries. It does not seal, does not choose a destination and
 * does not know whether it is being read for a backup or for the readability
 * check the compliance probe performs — which is what lets that check exercise
 * the real, configured sources without taking a real backup.
 *
 * WHAT A SOURCE MUST NOT DO is decide that it has nothing to contribute and stay
 * quiet about it. A source over an empty population yields no entries, and the
 * manifest then records zero entries for it, which is a fact an operator can act
 * on. A source that swallows its own read failure and yields nothing produces the
 * same archive as a healthy one over an empty estate, and the framework has no
 * way to tell those apart afterwards — so a failing source THROWS
 * {@see BackupException::sourceUnreadable()} and the backup fails.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface BackupSourceInterface
{
    /**
     * Stable identifier, used as the first path segment of every entry it yields
     * and in the manifest. Lower-case, no slashes: `database`, `audit`, `files`.
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * What this source covers, in one clause, for the manifest and the coverage
     * table an operator reads before trusting the archive.
     *
     * @return non-empty-string
     */
    public function describe(): string;

    /**
     * The entries, in order. Traversable once.
     *
     * @return iterable<BackupEntry>
     *
     * @throws BackupException when the estate cannot be read
     */
    public function entries(): iterable;
}
