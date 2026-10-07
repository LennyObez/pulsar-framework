<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use JsonException;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use SodiumException;
use Throwable;

use function bin2hex;
use function chr;
use function count;
use function dirname;
use function fclose;
use function filesize;
use function fopen;
use function fwrite;
use function hash_equals;
use function is_dir;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function mkdir;
use function ord;
use function sodium_crypto_secretstream_xchacha20poly1305_init_pull;
use function sodium_crypto_secretstream_xchacha20poly1305_pull;
use function sprintf;
use function strlen;
use function substr;
use function unlink;

use const DATE_RFC3339;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

/**
 * The shipped backup driver: one sealed, streaming archive per run.
 *
 * WHAT IS IN AN ARCHIVE is decided by the sources it is handed, not by this
 * class, and {@see \Pulsar\Core\Wiring\BackupWiring} composes the shipped set:
 * every user table in the primary database, and the file trees the deployment
 * configures — which include the audit chain, because an archive that silently
 * omitted the audit trail would be worse than none for a regulated deployment.
 * `docs/backup.md` states the boundary in one table, and the manifest this class
 * returns states what a given run actually wrote, so the two can be compared.
 *
 * MEMORY. Neither the archive nor any entry is held whole. The bound is one
 * {@see ArchiveFormat::CHUNK_SIZE} plaintext chunk (64 KiB) on either side, plus
 * one unit of whatever the source yields — a batch of database rows, one read
 * from a file — plus the frame buffer, which cannot exceed one chunk because
 * nothing longer than a chunk is ever written as a single frame. The one thing
 * that grows with the estate is the in-memory manifest: one {@see ArchivedEntry}
 * per entry, which is a name and two integers per TABLE and per FILE. That bound
 * is stated rather than engineered away, because an archive whose entry list
 * cannot be held is an archive whose entry list also cannot be printed for the
 * operator, and the list is the thing that shows the audit trail is in there.
 *
 * TWO SEPARATE INTEGRITY MECHANISMS, and they catch different failures. The AEAD
 * stream ({@see SealedArchiveWriter}) catches everything that happens to the file
 * AFTER it is written: modification, reordering, truncation, and reading it with
 * the wrong key. The per-entry digest catches what happens BEFORE: a source that
 * yielded a short read, a target that consumed fewer bytes than it was handed.
 * The second is not redundant, because a producer bug leaves the seal valid.
 *
 * WHY A FAILED BACKUP DELETES ITS OWN ARCHIVE. A partial file that looks like an
 * archive is the single most dangerous artefact this class can leave behind: it
 * has a valid header, it sits where the operator expects the backup, and it is a
 * truncated copy that would be discovered during the recovery it exists to
 * support. {@see backUp()} removes the destination on any failure and lets the
 * exception out.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SealedArchiveBackupService implements BackupServiceInterface
{
    /** Directory permissions for a destination directory this service creates. */
    private const int DIRECTORY_PERMISSIONS = 0o750;

    public function __construct(
        private ArchiveSeal $seal,
    ) {}

    /**
     * IT NEVER WRITES OVER A DESTINATION THAT ALREADY HOLDS A FILE. The archive is
     * opened with `x`, so it is the filesystem that refuses, atomically, and two
     * runs racing for one path cannot both find it free. Every other writer in this
     * module already refuses what it finds -- {@see FileTreeRestoreTarget} an
     * existing file, {@see DatabaseRestoreTarget} a populated table -- and the
     * destination of a backup is the one file whose loss cannot be recovered from,
     * because the thing that would have recovered it is what overwrote it. A refused
     * run is a message an operator acts on; a truncated archive is a discovery made
     * during a recovery.
     *
     * @param non-empty-string                $archivePath
     * @param iterable<BackupSourceInterface> $sources
     *
     * @throws BackupException
     */
    #[Override]
    public function backUp(string $archivePath, iterable $sources): BackupManifest
    {
        $this->ensureDirectory($archivePath);

        // 'xb', not 'wb'. The mode is the check: it is the filesystem that refuses,
        // atomically, so two runs racing for the same destination cannot both decide
        // the path is free and then have one truncate the other's archive. A
        // separate is_file() test before an open would be exactly that race.
        $handle = @fopen($archivePath, 'xb');

        if ($handle === false) {
            throw is_file($archivePath)
                ? BackupException::destinationOccupied($archivePath)
                : BackupException::destinationUnwritable($archivePath, 'fopen() refused the path');
        }

        try {
            $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $keyId = $this->keyId();

            try {
                $header = ArchiveFormat::header($keyId, $createdAt->format(DATE_RFC3339));
            } catch (JsonException $failure) {
                throw BackupException::destinationUnwritable($archivePath, $failure->getMessage());
            }

            $preamble = ArchiveFormat::MAGIC
                . chr(ArchiveFormat::VERSION)
                . ArchiveFormat::uint32(strlen($header))
                . $header;

            if (@fwrite($handle, $preamble) !== strlen($preamble)) {
                throw BackupException::destinationUnwritable($archivePath, 'the header could not be written');
            }

            /** @var array{0: list<ArchivedEntry>, 1: list<string>, 2: int<0, max>} $written */
            $written = $this->seal->withArchiveKey(
                fn(string $key): array => $this->writeEntries($handle, $key, $header, $archivePath, $sources),
            );

            return new BackupManifest(
                archivePath: $archivePath,
                createdAt: $createdAt,
                keyId: $keyId,
                entries: $written[0],
                sourceIds: $written[1],
                sealedBytes: strlen($preamble) + $written[2],
            );
        } catch (SodiumException $failure) {
            $this->discard($handle, $archivePath);

            throw BackupException::destinationUnwritable($archivePath, $failure->getMessage());
        } catch (Throwable $failure) {
            $this->discard($handle, $archivePath);

            throw $failure;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param non-empty-string $archivePath
     */
    #[Override]
    public function verify(string $archivePath): BackupVerification
    {
        try {
            $read = $this->readArchive($archivePath, null);
        } catch (BackupException $refusal) {
            return BackupVerification::refused($archivePath, $refusal->getMessage());
        } catch (SodiumException $failure) {
            return BackupVerification::refused($archivePath, $failure->getMessage());
        }

        return BackupVerification::readBack(
            archivePath: $archivePath,
            createdAt: $read['createdAt'],
            keyId: $read['keyId'],
            entries: $read['entries'],
            bytesRead: $read['bytesRead'],
        );
    }

    /**
     * @param non-empty-string             $archivePath
     * @param list<RestoreTargetInterface> $targets
     *
     * @throws BackupException
     */
    #[Override]
    public function restore(string $archivePath, array $targets): RestoreReport
    {
        try {
            $read = $this->readArchive($archivePath, $targets);
        } catch (SodiumException $failure) {
            throw BackupException::archiveUnreadable($archivePath, $failure->getMessage());
        }

        return new RestoreReport($archivePath, $read['restored'], $read['skipped']);
    }

    /**
     * @return non-empty-string
     *
     * @throws BackupException
     */
    #[Override]
    public function keyId(): string
    {
        try {
            return $this->seal->keyId();
        } catch (SodiumException $failure) {
            throw BackupException::archiveUnreadable(
                'the archive key',
                'the key hierarchy refused to derive an archive key: ' . $failure->getMessage(),
            );
        }
    }

    /**
     * Write every source's entries through the seal.
     *
     * @param resource                        $handle
     * @param iterable<BackupSourceInterface> $sources
     *
     * @return array{0: list<ArchivedEntry>, 1: list<string>, 2: int<0, max>}
     *
     * @throws BackupException
     * @throws SodiumException
     */
    private function writeEntries($handle, string $key, string $header, string $path, iterable $sources): array
    {
        $writer = new SealedArchiveWriter($handle, $key, $header, $path);

        /** @var list<ArchivedEntry> $entries */
        $entries = [];
        /** @var list<string> $sourceIds */
        $sourceIds = [];

        foreach ($sources as $source) {
            $sourceIds[] = $source->id();

            try {
                $produced = $source->entries();
            } catch (BackupException $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw BackupException::sourceUnreadable($source->id(), $failure->getMessage(), $failure);
            }

            foreach ($produced as $entry) {
                $entries[] = $this->writeEntry($writer, $source, $entry);
            }
        }

        $writer->append(chr(ArchiveFormat::FRAME_ARCHIVE_END) . ArchiveFormat::uint32(count($entries)));
        $writer->finish();

        return [$entries, $sourceIds, $writer->sealedBytes()];
    }

    /**
     * @throws BackupException
     * @throws SodiumException
     */
    private function writeEntry(
        SealedArchiveWriter $writer,
        BackupSourceInterface $source,
        BackupEntry $entry,
    ): ArchivedEntry {
        $name = $source->id() . '/' . $entry->name;

        if (!BackupEntry::isSafeName($name)) {
            throw BackupException::unsafeEntryName($name);
        }

        $writer->append(chr(ArchiveFormat::FRAME_ENTRY_BEGIN) . ArchiveFormat::uint32(strlen($name)) . $name);

        $digest = new EntryDigest();

        try {
            foreach ($entry->chunks as $piece) {
                $digest->add($piece);

                // Re-chunked here rather than trusted: a source is free to yield a
                // whole file in one string, and the frame length prefix — and the
                // reader's refusal to buffer more than one chunk — both assume no
                // frame exceeds CHUNK_SIZE.
                $offset = 0;

                while ($offset < strlen($piece)) {
                    $slice = substr($piece, $offset, ArchiveFormat::CHUNK_SIZE);
                    $offset += strlen($slice);

                    $writer->append(
                        chr(ArchiveFormat::FRAME_ENTRY_CHUNK) . ArchiveFormat::uint32(strlen($slice)) . $slice,
                    );
                }
            }
        } catch (BackupException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw BackupException::sourceUnreadable($source->id(), $failure->getMessage(), $failure);
        }

        $digest->close();

        $writer->append(
            chr(ArchiveFormat::FRAME_ENTRY_END) . $digest->digest() . ArchiveFormat::uint64($digest->bytes()),
        );

        return new ArchivedEntry($name, $digest->bytes(), bin2hex($digest->digest()));
    }

    /**
     * Read an archive back, optionally routing its entries into targets.
     *
     * One method for both {@see verify()} and {@see restore()} on purpose: a
     * verification that took a different path through the format than the restore
     * would be a verification of a code path nobody recovers through, and the
     * archives it certified would be the ones a restore had never touched.
     *
     * @param list<RestoreTargetInterface>|null $targets Null verifies only
     *
     * @return array{
     *     entries: list<ArchivedEntry>,
     *     restored: list<RestoredEntry>,
     *     skipped: list<string>,
     *     createdAt: DateTimeImmutable,
     *     keyId: non-empty-string,
     *     bytesRead: int,
     * }
     *
     * @throws BackupException
     * @throws SodiumException
     */
    private function readArchive(string $path, ?array $targets): array
    {
        if (!is_file($path)) {
            throw BackupException::archiveUnreadable($path, 'no such file');
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw BackupException::archiveUnreadable($path, 'fopen() refused the path');
        }

        try {
            $head = $this->readPreamble($handle, $path);

            /**
             * @var array{
             *     entries: list<ArchivedEntry>,
             *     restored: list<RestoredEntry>,
             *     skipped: list<string>,
             * } $collected
             */
            $collected = $this->seal->withArchiveKey(
                fn(string $key): array => self::consume($handle, $key, $head['header'], $path, $targets),
            );

            $size = @filesize($path);

            return [
                'entries' => $collected['entries'],
                'restored' => $collected['restored'],
                'skipped' => $collected['skipped'],
                'createdAt' => $head['createdAt'],
                'keyId' => $head['keyId'],
                'bytesRead' => $size === false ? 0 : $size,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read and check the plaintext header.
     *
     * @param resource $handle
     *
     * @return array{header: string, keyId: non-empty-string, createdAt: DateTimeImmutable}
     *
     * @throws BackupException
     */
    private function readPreamble($handle, string $path): array
    {
        $magic = ArchiveFormat::readExactly($handle, strlen(ArchiveFormat::MAGIC));

        if ($magic !== ArchiveFormat::MAGIC) {
            throw BackupException::notAnArchive($path, 'it does not begin with the Pulsar archive magic');
        }

        $version = ArchiveFormat::readExactly($handle, 1);

        if ($version === null || ord($version) !== ArchiveFormat::VERSION) {
            throw BackupException::notAnArchive(
                $path,
                sprintf(
                    'it declares archive format %s, and this release reads %d',
                    $version === null ? 'nothing' : (string) ord($version),
                    ArchiveFormat::VERSION,
                ),
            );
        }

        $lengthBytes = ArchiveFormat::readExactly($handle, 4);
        $length = $lengthBytes === null ? null : ArchiveFormat::readUint32($lengthBytes, 0);

        if ($length === null || $length <= 0 || $length > ArchiveFormat::MAX_HEADER_BYTES) {
            throw BackupException::notAnArchive($path, 'its header length is missing or implausible');
        }

        $header = ArchiveFormat::readExactly($handle, $length);

        if ($header === null) {
            throw BackupException::archiveTruncated($path);
        }

        $parsed = ArchiveFormat::parseHeader($header);

        if ($parsed === null) {
            throw BackupException::notAnArchive($path, 'its header is not readable metadata');
        }

        if ($parsed['format'] !== ArchiveFormat::VERSION) {
            throw BackupException::notAnArchive(
                $path,
                sprintf(
                    'its header declares format %d, and this release reads %d',
                    $parsed['format'],
                    ArchiveFormat::VERSION,
                ),
            );
        }

        $deploymentKeyId = $this->keyId();

        if (!hash_equals($deploymentKeyId, $parsed['key_id'])) {
            throw BackupException::sealedUnderAnotherKey($path, $parsed['key_id'], $deploymentKeyId);
        }

        $createdAt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $parsed['created_at']);

        if ($createdAt === false) {
            throw BackupException::notAnArchive($path, 'its recorded creation instant is not a timestamp');
        }

        /** @var non-empty-string $keyId */
        $keyId = $parsed['key_id'];

        return ['header' => $header, 'keyId' => $keyId, 'createdAt' => $createdAt];
    }

    /**
     * Walk the sealed frame stream, digesting every entry and routing it.
     *
     * @param resource                          $handle
     * @param list<RestoreTargetInterface>|null $targets
     *
     * @return array{entries: list<ArchivedEntry>, restored: list<RestoredEntry>, skipped: list<string>}
     *
     * @throws BackupException
     * @throws SodiumException
     */
    private static function consume($handle, string $key, string $header, string $path, ?array $targets): array
    {
        $frames = self::frames($handle, $key, $header, $path);

        /** @var list<ArchivedEntry> $entries */
        $entries = [];
        /** @var list<RestoredEntry> $restored */
        $restored = [];
        /** @var list<string> $skipped */
        $skipped = [];

        $declared = null;

        while (($frame = self::nextFrame($frames)) !== null) {
            if ($frame['type'] === ArchiveFormat::FRAME_ARCHIVE_END) {
                $declared = ArchiveFormat::readUint32($frame['data'], 0);
                $frames->next();

                continue;
            }

            if ($frame['type'] !== ArchiveFormat::FRAME_ENTRY_BEGIN) {
                throw BackupException::malformedPayload($path, 'an entry does not begin where one was expected');
            }

            $name = $frame['data'];

            // Emptiness is checked beside safety rather than left to isSafeName(),
            // which refuses it too: this is where a name read out of an archive
            // becomes one this release will hand to a restore target, and the
            // targets take a name that is known to be there.
            if ($name === '' || !BackupEntry::isSafeName($name)) {
                throw BackupException::unsafeEntryName($name);
            }

            $frames->next();

            $digest = new EntryDigest();
            $chunks = self::entryChunks($frames, $digest, $path);

            $target = self::targetFor($targets, $name);

            if ($target === null) {
                self::drain($chunks);

                if ($targets !== null) {
                    $skipped[] = $name;
                }
            } else {
                try {
                    $consumed = $target->restore($name, $chunks);
                } catch (BackupException $failure) {
                    throw $failure;
                } catch (Throwable $failure) {
                    throw BackupException::restoreFailed($name, $failure->getMessage(), $failure);
                }

                // A target that stopped early would otherwise leave the frame
                // stream mid-entry and desynchronise every entry after it.
                self::drain($chunks);

                if ($consumed !== $digest->bytes()) {
                    throw BackupException::restoreFailed(
                        $name,
                        sprintf(
                            'the target reported %d byte(s) written and the archive holds %d',
                            $consumed,
                            $digest->bytes(),
                        ),
                    );
                }

                $restored[] = new RestoredEntry($name, $target->id(), $consumed);
            }

            self::assertEntryIntact($digest, $name, $path);

            $entries[] = new ArchivedEntry($name, $digest->bytes(), bin2hex($digest->digest()));
        }

        if ($declared === null) {
            throw BackupException::archiveTruncated($path);
        }

        if ($declared !== count($entries)) {
            throw BackupException::malformedPayload(
                $path,
                sprintf('it declares %d entries and holds %d', $declared, count($entries)),
            );
        }

        return ['entries' => $entries, 'restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * The frame the generator is sitting on, or null when it has none left.
     *
     * `Generator::current()` is typed as returning the yielded type OR null -- it
     * returns null once the generator is done -- so reading it behind a separate
     * `valid()` call leaves every field access on a value that may not be there.
     * One seam turns that into the loop condition, and the callers below read a
     * frame that exists.
     *
     * @param Generator<int, array{type: int, data: string}> $frames
     *
     * @return array{type: int, data: string}|null
     */
    #[NoDiscard]
    private static function nextFrame(Generator $frames): ?array
    {
        return $frames->valid() ? $frames->current() : null;
    }

    /**
     * The entry's recorded digest and length must match what actually came out.
     *
     * @throws BackupException
     * @throws SodiumException
     */
    private static function assertEntryIntact(EntryDigest $digest, string $name, string $path): void
    {
        $recordedDigest = $digest->recordedDigest();
        $recordedBytes = $digest->recordedBytes();

        if ($recordedDigest === null || $recordedBytes === null) {
            throw BackupException::malformedPayload($path, sprintf('entry "%s" has no end marker', $name));
        }

        if (!hash_equals($recordedDigest, $digest->digest()) || $recordedBytes !== $digest->bytes()) {
            throw BackupException::entryDigestMismatch($path, $name);
        }
    }

    /**
     * @param list<RestoreTargetInterface>|null $targets
     */
    private static function targetFor(?array $targets, string $name): ?RestoreTargetInterface
    {
        if ($targets === null) {
            return null;
        }

        foreach ($targets as $target) {
            if ($target->accepts($name)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @param Generator<int, string> $chunks
     */
    private static function drain(Generator $chunks): void
    {
        while ($chunks->valid()) {
            $chunks->next();
        }
    }

    /**
     * The content of one entry, as chunks, ending when its END frame arrives.
     *
     * @param Generator<int, array{type: int, data: string}> $frames
     *
     * @return Generator<int, string>
     *
     * @throws BackupException
     * @throws SodiumException
     */
    private static function entryChunks(Generator $frames, EntryDigest $digest, string $path): Generator
    {
        while (($frame = self::nextFrame($frames)) !== null) {
            if ($frame['type'] === ArchiveFormat::FRAME_ENTRY_END) {
                $digest->close(
                    substr($frame['data'], 0, ArchiveFormat::DIGEST_BYTES),
                    ArchiveFormat::readUint64($frame['data'], ArchiveFormat::DIGEST_BYTES),
                );
                $frames->next();

                return;
            }

            if ($frame['type'] !== ArchiveFormat::FRAME_ENTRY_CHUNK) {
                throw BackupException::malformedPayload($path, 'an entry is interrupted by an unexpected frame');
            }

            $digest->add($frame['data']);
            $frames->next();

            yield $frame['data'];
        }

        throw BackupException::archiveTruncated($path);
    }

    /**
     * Decrypt the archive chunk by chunk and emit the frames it carries.
     *
     * @param resource $handle
     *
     * @return Generator<int, array{type: int, data: string}>
     *
     * @throws BackupException
     * @throws SodiumException
     */
    private static function frames($handle, string $key, string $header, string $path): Generator
    {
        $streamHeader = ArchiveFormat::readExactly(
            $handle,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
        );

        if ($streamHeader === null) {
            throw BackupException::archiveTruncated($path);
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);

        $buffer = '';
        $final = false;
        $index = 0;

        while (true) {
            while (($frame = self::takeFrame($buffer, $path)) !== null) {
                yield $frame;
            }

            if ($final) {
                if ($buffer !== '') {
                    throw BackupException::malformedPayload($path, 'it ends inside an incomplete frame');
                }

                return;
            }

            $lengthBytes = ArchiveFormat::readExactly($handle, 4);

            if ($lengthBytes === null) {
                throw BackupException::archiveTruncated($path);
            }

            $length = ArchiveFormat::readUint32($lengthBytes, 0);

            if ($length === null || $length <= 0 || $length > ArchiveFormat::MAX_SEALED_CHUNK_BYTES) {
                throw BackupException::malformedPayload($path, 'a sealed chunk declares an implausible length');
            }

            $sealed = ArchiveFormat::readExactly($handle, $length);

            if ($sealed === null) {
                throw BackupException::archiveTruncated($path);
            }

            try {
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $sealed, $header);
            } catch (SodiumException) {
                $opened = false;
            }

            // `false` is what libsodium returns for a chunk that does not
            // authenticate; the shape of a success is checked too rather than
            // destructured on faith, because anything else coming back would be
            // appended to the frame buffer as plaintext nobody has authenticated.
            $plain = $opened === false ? null : ($opened[0] ?? null);
            $tag = $opened === false ? null : ($opened[1] ?? null);

            if (!is_string($plain) || !is_int($tag)) {
                throw BackupException::sealBroken($path, $index);
            }

            $buffer .= $plain;
            $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            ++$index;
        }
    }

    /**
     * Take the next complete frame out of $buffer, or null when more bytes are needed.
     *
     * @return array{type: int, data: string}|null
     *
     * @throws BackupException
     */
    private static function takeFrame(string &$buffer, string $path): ?array
    {
        if ($buffer === '') {
            return null;
        }

        $type = ord($buffer[0]);

        $fixed = match ($type) {
            ArchiveFormat::FRAME_ENTRY_END => ArchiveFormat::DIGEST_BYTES + 8,
            ArchiveFormat::FRAME_ARCHIVE_END => 4,
            ArchiveFormat::FRAME_ENTRY_BEGIN, ArchiveFormat::FRAME_ENTRY_CHUNK => null,
            default => throw BackupException::malformedPayload(
                $path,
                sprintf('it carries an unknown frame type %d', $type),
            ),
        };

        if ($fixed !== null) {
            if (strlen($buffer) < 1 + $fixed) {
                return null;
            }

            $data = substr($buffer, 1, $fixed);
            $buffer = substr($buffer, 1 + $fixed);

            return ['type' => $type, 'data' => $data];
        }

        $length = ArchiveFormat::readUint32($buffer, 1);

        if ($length === null) {
            return null;
        }

        if ($length <= 0 || $length > ArchiveFormat::CHUNK_SIZE) {
            throw BackupException::malformedPayload($path, 'a frame declares an implausible length');
        }

        if (strlen($buffer) < 5 + $length) {
            return null;
        }

        $data = substr($buffer, 5, $length);
        $buffer = substr($buffer, 5 + $length);

        return ['type' => $type, 'data' => $data];
    }

    /**
     * @throws BackupException
     */
    private function ensureDirectory(string $archivePath): void
    {
        $directory = dirname($archivePath);

        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, self::DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
            throw BackupException::destinationUnwritable(
                $archivePath,
                sprintf('the directory "%s" does not exist and cannot be created', $directory),
            );
        }
    }

    /**
     * Remove a half-written archive.
     *
     * @param resource $handle
     */
    private function discard($handle, string $archivePath): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }

        if (is_file($archivePath)) {
            @unlink($archivePath);
        }
    }
}
