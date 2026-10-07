<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;

use function chmod;
use function dirname;
use function fclose;
use function feof;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rewind;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;

/**
 * A pseudonym mapping table that survives the process that wrote it.
 *
 * WHY IT EXISTS. {@see InMemoryPseudonymLookup} is the only lookup this
 * framework shipped, and its own attribute says what it is: "Test/dev pseudonym
 * lookup implementation". A pseudonymisation service standing on it can produce
 * a pseudonym and can never resolve one again after a restart — which means it
 * cannot honour an Art 15 access request about the pseudonymised record, and
 * cannot honour the Art 17 erasure that {@see ForgetService} exists to perform,
 * because there is nothing left to erase. `compliance:report` said exactly that
 * of GDPR Art 25 and ISO 27001 A.8.12: nothing answered
 * {@see PseudonymizationServiceInterface}, and nothing could have, because
 * binding the service over the development stub would have been the same claim
 * one layer down.
 *
 * WHAT IT HOLDS, and why the storage is deliberately plain. Art 4(5) calls the
 * mapping "additional information" that must be "kept separately and subject to
 * technical and organisational measures". Separately is a deployment decision —
 * the constructor takes a path and the composition root resolves it through
 * {@see \Pulsar\Filesystem\WritablePathGuard}, so the table cannot land in the
 * document root. The measures are: the directory is created 0750, the file 0600,
 * and the SALT in every record arrives already encrypted by
 * {@see PseudonymizationService} under a derived subkey. So the file discloses
 * which subject ids have pseudonyms and what those pseudonyms are; it does not
 * disclose the salt that would let a holder of the file re-derive one offline.
 * That is the boundary this class draws, stated so nobody has to infer it: it is
 * a re-identification table and must be protected like one.
 *
 * WHY A WHOLE-DOCUMENT REWRITE rather than the append-only JSONL that
 * {@see \Pulsar\Security\Incident\FileIncidentReporter} uses. That log never
 * deletes; this table must, because erasure IS the control it serves. An
 * append-only file with tombstones would leave the erased subject id in the file
 * after the deletion it was asked to perform, which fails the requirement while
 * reporting success. Every mutation therefore takes an exclusive lock on the
 * file, reads the whole document, rewrites it, and unlocks — so a concurrent
 * store and delete cannot interleave into a table that has lost one of them.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class FilePseudonymLookup implements PseudonymLookupInterface
{
    private const int DIR_PERMISSIONS = 0o750;

    private const int FILE_PERMISSIONS = 0o600;

    /**
     * @param string $path Absolute path to the mapping table. Resolve it through
     *        {@see \Pulsar\Filesystem\WritablePathGuard::resolveState()} first: a
     *        re-identification table inside the document root is served to anyone
     *        who guesses the name
     */
    public function __construct(
        private string $path,
    ) {}

    #[Override]
    public function store(string $subjectId, string $pseudonym, string $encryptedSalt): void
    {
        $mapping = new PseudonymMapping(
            subjectId: $subjectId,
            pseudonym: $pseudonym,
            encryptedSalt: $encryptedSalt,
            createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $this->mutate(static function (array $records) use ($subjectId, $mapping): array {
            $records[$subjectId] = $mapping->toArray();

            return $records;
        });
    }

    #[Override]
    public function findBySubjectId(string $subjectId): ?PseudonymMapping
    {
        $record = $this->read()[$subjectId] ?? null;

        return $record === null ? null : PseudonymMapping::fromArray($record);
    }

    #[Override]
    public function findByPseudonym(string $pseudonym): ?PseudonymMapping
    {
        foreach ($this->read() as $record) {
            if (($record['pseudonym'] ?? null) === $pseudonym) {
                return PseudonymMapping::fromArray($record);
            }
        }

        return null;
    }

    #[Override]
    public function delete(string $subjectId): bool
    {
        $deleted = false;

        $this->mutate(static function (array $records) use ($subjectId, &$deleted): array {
            if (!isset($records[$subjectId])) {
                return $records;
            }

            $deleted = true;
            unset($records[$subjectId]);

            return $records;
        });

        return $deleted;
    }

    /**
     * The whole table, keyed by subject id.
     *
     * A missing file is an empty table and not an error: nothing has been
     * pseudonymised yet. A file that exists and cannot be read IS an error —
     * answering "no mapping" from an unreadable table would report an erasure as
     * already done and a live pseudonym as unknown.
     *
     * @return array<string, array{subject_id?: string|null, pseudonym?: string|null, encrypted_salt?: string|null, created_at?: string|null}>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw SecurityException::encryptionFailed(
                sprintf('Could not open the pseudonym mapping table at "%s"', $this->path),
            );
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw SecurityException::encryptionFailed(
                    sprintf('Could not lock the pseudonym mapping table at "%s" for reading', $this->path),
                );
            }

            $contents = $this->readAll($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return self::decode($contents);
    }

    /**
     * Apply a change to the whole table under an exclusive lock.
     *
     * @param callable(array<string, array<string, string>>): array<string, array<string, string>> $change
     */
    private function mutate(callable $change): void
    {
        $this->ensureDirectory();

        // 'c+' creates the file when absent and does NOT truncate it when present,
        // so the lock is taken before anything is read and before anything is
        // discarded. 'w+' would truncate at fopen() — losing the whole table to a
        // caller that then blocks on the lock, or fails.
        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw SecurityException::encryptionFailed(
                sprintf('Could not open the pseudonym mapping table at "%s"', $this->path),
            );
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw SecurityException::encryptionFailed(
                    sprintf('Could not lock the pseudonym mapping table at "%s" for writing', $this->path),
                );
            }

            /** @var array<string, array<string, string>> $records */
            $records = self::decode($this->readAll($handle));
            $encoded = self::encode($change($records));

            if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0 || fwrite($handle, $encoded) === false) {
                throw SecurityException::encryptionFailed(
                    sprintf('Could not write the pseudonym mapping table at "%s"', $this->path),
                );
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        // After the write, not before: a file created 0600 and then written is
        // still 0600, and chmod on a path that does not yet exist fails silently
        // on some platforms.
        chmod($this->path, self::FILE_PERMISSIONS);
    }

    /**
     * Read the open handle to the end, from the start, without releasing the lock.
     *
     * `file_get_contents()` would reopen the path and read it outside the lock
     * this method's callers hold, which is the whole reason it is not used.
     *
     * @param resource $handle
     */
    private function readAll($handle): string
    {
        rewind($handle);
        $contents = '';

        while (!feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                throw SecurityException::encryptionFailed(sprintf(
                    'Could not read the pseudonym mapping table at "%s"',
                    $this->path,
                ));
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * @return array<string, array{subject_id?: string|null, pseudonym?: string|null, encrypted_salt?: string|null, created_at?: string|null}>
     */
    private static function decode(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Deliberately not "treat as empty". A corrupt table is not an absence
            // of mappings, and answering an erasure request from one would report
            // a deletion that never happened.
            throw SecurityException::encryptionFailed(
                sprintf('The pseudonym mapping table is not readable JSON: %s', $e->getMessage()),
            );
        }

        if (!is_array($decoded)) {
            throw SecurityException::encryptionFailed(
                'The pseudonym mapping table does not hold a JSON object of mappings.',
            );
        }

        $records = [];

        /** @var mixed $record */
        foreach ($decoded as $subjectId => $record) {
            if (!is_string($subjectId) || !is_array($record)) {
                continue;
            }

            /** @var array{subject_id?: string|null, pseudonym?: string|null, encrypted_salt?: string|null, created_at?: string|null} $record */
            $records[$subjectId] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, array<string, string>> $records
     */
    private static function encode(array $records): string
    {
        try {
            return json_encode(
                $records,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw SecurityException::encryptionFailed(
                sprintf('The pseudonym mapping table could not be encoded: %s', $e->getMessage()),
            );
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, self::DIR_PERMISSIONS, true) && !is_dir($directory)) {
            throw SecurityException::encryptionFailed(
                sprintf('Could not create the pseudonym mapping directory "%s"', $directory),
            );
        }
    }
}
