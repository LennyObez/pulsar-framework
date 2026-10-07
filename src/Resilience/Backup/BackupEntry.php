<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Api;

use function preg_match;
use function str_contains;
use function str_starts_with;

/**
 * One named blob on its way into, or out of, an archive — as a stream, never as a value.
 *
 * `$chunks` is an `iterable<string>` and is very often a generator, which is the
 * whole reason this type exists rather than a `string $content`: a table with two
 * million rows and a 4 GB upload directory both have to reach the archive without
 * either being a PHP string first. A caller may traverse `$chunks` exactly once,
 * and {@see SealedArchiveBackupService} does exactly that.
 *
 * THE NAME IS A RELATIVE PATH AND IS VALIDATED HERE. It is the one field of the
 * format that a restore turns back into a filesystem path, and validating it at
 * construction means every producer and every archive reader inherits the same
 * refusal — a target cannot be the place it is checked, because the next target
 * somebody writes would have to remember to check it again. Absolute paths,
 * parent-directory segments, backslashes and NUL bytes are refused outright
 * rather than sanitised: a name that needs rewriting to be safe is a bug in the
 * producer, and silently rewriting it would restore data somewhere other than
 * where the operator believes it went.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupEntry
{
    /**
     * Characters an entry name may never contain: NUL, which truncates a path at
     * the syscall boundary, and the backslash, which is a directory separator on
     * Windows and would escape a containment check written for forward slashes.
     */
    private const string FORBIDDEN_CHARACTERS = '~[\x00\x5c]~';

    /** A Windows drive-letter prefix, which makes the rest of the name absolute. */
    private const string DRIVE_PREFIX = '~^[A-Za-z]:~';

    /**
     * @param non-empty-string $name   Relative, forward-slashed path inside the archive,
     *                                 e.g. `database/orders.ndjson` or `files/logo.png`
     * @param iterable<string> $chunks The content, in order. Traversable once
     *
     * @throws BackupException when the name is not a safe relative path
     */
    public function __construct(
        public string $name,
        public iterable $chunks,
    ) {
        if (!self::isSafeName($name)) {
            throw BackupException::unsafeEntryName($name);
        }
    }

    /**
     * Whether a name may be used as an archive entry, and therefore as a path
     * fragment under a restore root.
     *
     * Shared with {@see SealedArchiveBackupService}, which re-applies it to every
     * name it READS out of an archive. The archive is authenticated, so a name in
     * it came from a producer holding this deployment's key — but "authentic" and
     * "safe to concatenate with a directory" are different properties, and the
     * reader opens archives written by producers this release did not compile.
     *
     * It answers ONE question -- is this name safe -- and callers that also need a
     * `non-empty-string` say `$name === ''` themselves. Stating the emptiness half
     * as a type assertion here would make the analyser read the whole method as an
     * emptiness guard and report the traversal check as a call that cannot fail.
     */
    #[NoDiscard]
    public static function isSafeName(string $name): bool
    {
        if ($name === '' || preg_match(self::FORBIDDEN_CHARACTERS, $name) === 1) {
            return false;
        }

        if (str_starts_with($name, '/') || preg_match(self::DRIVE_PREFIX, $name) === 1) {
            return false;
        }

        if ($name === '.' || $name === '..' || str_starts_with($name, '../') || str_contains($name, '/../')) {
            return false;
        }

        return !str_contains($name, '//') && !str_starts_with($name, './');
    }
}
