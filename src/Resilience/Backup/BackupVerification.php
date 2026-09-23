<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * What reading an archive back actually showed.
 *
 * A BACKUP THAT HAS NEVER BEEN READ BACK IS A BELIEF. That is the whole reason
 * this type exists, and it is why {@see BackupServiceInterface::verify()} is not
 * a `bool`: an operator whose verification fails needs to know which of the four
 * distinguishable things went wrong — the file is not an archive, it was sealed
 * under another key, a byte changed, or it stops early — because the remedy
 * differs for each and only one of them means the data is gone.
 *
 * VERIFICATION IS NOT A CHEAPER SUBSTITUTE FOR A RESTORE DRILL, and the boundary
 * is stated here so nobody has to infer it. Verification decrypts every chunk,
 * re-frames every entry and recomputes every entry digest — so it proves the
 * archive is intact, complete, and readable with the key this deployment holds.
 * It does NOT prove the database will accept the rows back, that the schema still
 * matches, or that the restore fits in the recovery window; only a restore into a
 * real target proves those, which is what `pulsar backup:restore` against a
 * scratch database is for and what the 72-hour obligation in HIPAA
 * §164.308(a)(7) actually asks about.
 *
 * The verdict is COMPUTED from whether a refusal was recorded, never passed in:
 * see {@see intact()}. There is no constructor that takes a boolean.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupVerification
{
    /**
     * @param non-empty-string    $archivePath
     * @param non-empty-string    $keyId       The archive key id read from the header
     * @param list<ArchivedEntry> $entries     Entries whose content was read back and re-digested
     * @param string|null         $refusal     Why the archive was refused, or null if it was not
     */
    private function __construct(
        public string $archivePath,
        public ?DateTimeImmutable $createdAt,
        public string $keyId,
        public array $entries,
        public ?string $refusal,
        public int $bytesRead,
    ) {}

    /**
     * Every chunk authenticated, the stream ended with its final tag, and every
     * entry digest matched.
     *
     * @param non-empty-string    $archivePath
     * @param non-empty-string    $keyId
     * @param list<ArchivedEntry> $entries
     */
    #[NoDiscard]
    public static function readBack(
        string $archivePath,
        DateTimeImmutable $createdAt,
        string $keyId,
        array $entries,
        int $bytesRead,
    ): self {
        return new self($archivePath, $createdAt, $keyId, $entries, null, $bytesRead);
    }

    /**
     * The archive was refused, and this is what refused it.
     *
     * A REFUSAL ALWAYS CARRIES A SENTENCE. The reason arrives as an exception
     * message, and an exception is free to carry an empty one; a verification whose
     * `refusal` is `''` reads as a blank line in `pulsar backup:verify` and as an
     * empty detail in the compliance report — an archive refused for no stated
     * reason, which is the one thing an operator cannot act on. The empty case is
     * replaced here, at the only place a refusal is constructed, rather than at
     * each of the readers.
     *
     * @param non-empty-string $archivePath
     * @param non-empty-string $keyId
     */
    #[NoDiscard]
    public static function refused(string $archivePath, string $reason, string $keyId = 'unknown'): self
    {
        return new self(
            $archivePath,
            null,
            $keyId,
            [],
            $reason === '' ? 'the archive was refused and the refusal carried no message' : $reason,
            0,
        );
    }

    /**
     * Whether the archive read back whole.
     *
     * Derived from the refusal, so there is no way to construct a verification
     * that reports intact while naming what went wrong, and none that reports
     * broken while naming nothing.
     */
    #[NoDiscard]
    public function intact(): bool
    {
        return $this->refusal === null;
    }

    #[NoDiscard]
    public function entryCount(): int
    {
        return count($this->entries);
    }
}
