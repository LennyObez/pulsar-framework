<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Api;

use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * The directory this deployment writes archives to, resolved once at boot.
 *
 * A type rather than a bound string, for the reason every path in this framework
 * that matters is a type: a `string` in the container is indistinguishable from
 * any other string a later wiring binds, and the one thing that must not happen
 * to a backup destination is quietly becoming somewhere else. It is produced by
 * {@see \Pulsar\Core\Wiring\BackupWiring} after {@see \Pulsar\Filesystem\WritablePathGuard}
 * has refused a value inside the document root, so holding one of these is
 * holding a path that has already been judged.
 *
 * {@see nextArchive()} is here rather than in the console command because the
 * naming convention is part of the format's contract with an operator: archives
 * sort chronologically by name, so `ls` in the destination is a backup history.
 *
 * THE NAME IS NOT WHAT KEEPS TWO RUNS APART, and this is stated because the
 * opposite is the natural assumption. The name resolves to the second, so two
 * runs started inside the same second ask for the same path. What stops the
 * second from destroying the first is
 * {@see SealedArchiveBackupService::backUp()}, which refuses a destination that
 * already holds a file rather than truncating it -- a refused run an operator
 * repeats, instead of an archive that quietly stopped existing. A naming scheme
 * fine enough to make a collision unlikely would still only make it unlikely,
 * and "unlikely" is not a property to give the one file a recovery depends on.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupDestination
{
    /** The extension every archive carries, so an operator can find them with one glob. */
    public const string EXTENSION = '.pulsarbk';

    public function __construct(
        public string $directory,
    ) {}

    /**
     * A path for a new archive, named for the instant it was started.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public function nextArchive(?DateTimeImmutable $at = null): string
    {
        $when = $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Concatenated rather than sprintf()-ed so the result is a non-empty string
        // by construction: the extension is a non-empty literal, so no directory
        // value -- including the empty one -- can produce a path that is not one.
        return rtrim($this->directory, '/\\')
            . DIRECTORY_SEPARATOR
            . $when->format('Y-m-d\THis\Z')
            . self::EXTENSION;
    }
}
