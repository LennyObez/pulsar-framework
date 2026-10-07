<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use DateTimeImmutable;
use Exception;
use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditChainState;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
use function trim;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * Append-only JSONL evidence store.
 *
 * The default for any deployment that keeps a durable security record, and the
 * reason is the one that made {@see \Pulsar\Security\Incident\FileIncidentReporter}
 * the default incident register: evidence held in process memory is gone at the
 * end of the request that produced it, and an auditor asking what the controls
 * looked like last quarter is asking a question an in-memory store cannot answer.
 * {@see EvidenceChain} additionally links each record to its predecessor's
 * signature, and a chain whose predecessors do not survive a restart links
 * nothing.
 *
 * One record per line, written with LOCK_EX so concurrent workers append rather
 * than interleave. Lines that do not decode into an evidence record are skipped
 * on read rather than throwing: a truncated final line (a crash mid-append) must
 * not make the whole register unreadable, and the chain verification in
 * {@see EvidenceChain::verifyChain()} is what reports tampering — silence here
 * would hide nothing, because a missing record breaks the linkage of the next one.
 *
 * Skipping is the right answer for READING and the wrong one for RESUMING, so the
 * skip is not silent to everyone: {@see chainState()} reports that a line was
 * unreadable, and {@see EvidenceChain} refuses to append onto a register it
 * cannot fully read. Without that, truncating the last record would simply move
 * the chain's tail back one, and the next append would close over the gap.
 *
 * Beside the register sits its anchor, one file named by {@see headPath()}
 * holding the chain's signed {@see EvidenceChainHead}. It is what makes removal
 * from the END of the register detectable: the JSONL file alone cannot say how
 * many lines it is supposed to have, and a truncated linked list is still a
 * linked list. See {@see EvidenceChainHeadAware}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class FileEvidenceStore implements EvidenceChainStateAware, EvidenceChainHeadAware
{
    private const int DIR_PERMISSIONS = 0o750;

    /**
     * Suffix of the anchor file, beside the register rather than inside it.
     *
     * Inside would defeat the purpose: an anchor appended as the register's last
     * line is removed by the same cut that removes the records it attests.
     */
    private const string HEAD_SUFFIX = '.head';

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * @throws EvidenceWriteFailedException when the record cannot be appended
     */
    #[Override]
    public function store(EvidenceRecord $record): void
    {
        $this->ensureDirectory();

        try {
            $line = json_encode(
                self::encode($record),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        } catch (JsonException $e) {
            throw EvidenceWriteFailedException::notEncodable($record->id, $e);
        }

        // The native warning from an unwritable target is suppressed for the
        // duration of the call and turned into the exception below. A raw PHP
        // warning would escape into whatever output the caller was producing —
        // for a boot-time evidence write, into the HTTP response.
        set_error_handler(static fn(): bool => true);

        try {
            $written = file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            throw EvidenceWriteFailedException::notWritable($this->path);
        }
    }

    #[Override]
    public function forControl(string $controlId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(EvidenceRecord $r): bool => $r->controlId === $controlId,
        ));
    }

    #[Override]
    public function all(): array
    {
        return $this->read()['records'];
    }

    /**
     * Report whether the register is empty, fully readable, or holds a line that
     * could not be decoded.
     *
     * Any undecodable line counts, not only the last one. A cut-out record in the
     * middle is already caught by the linkage check on its successor, but a
     * register that has been edited at all is not one to keep appending to, and
     * the two cases are the same operator decision.
     */
    #[Override]
    public function chainState(): AuditChainState
    {
        $read = $this->read();

        if ($read['unreadable']) {
            return AuditChainState::Corrupted;
        }

        return $read['records'] === [] ? AuditChainState::Empty : AuditChainState::Healthy;
    }

    #[Override]
    public function hasHead(): bool
    {
        return is_file($this->headPath());
    }

    #[Override]
    public function head(): ?EvidenceChainHead
    {
        if (!is_file($this->headPath())) {
            return null;
        }

        // Suppressed for the duration of the read for the reason store() suppresses
        // its write: a raw warning from an unreadable anchor would escape into
        // whatever output the caller was producing. The contract is "never throws",
        // and an anchor that cannot be read is reported as one that cannot be read.
        set_error_handler(static fn(): bool => true);

        try {
            $contents = file_get_contents($this->headPath());
        } finally {
            restore_error_handler();
        }

        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode(trim($contents), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return EvidenceChainHead::fromArray($decoded);
    }

    /**
     * @throws EvidenceWriteFailedException when the anchor cannot be written
     */
    #[Override]
    public function writeHead(EvidenceChainHead $head): void
    {
        $this->ensureDirectory();

        try {
            $body = json_encode(
                $head->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        } catch (JsonException $e) {
            throw EvidenceWriteFailedException::headNotEncodable($this->headPath(), $e);
        }

        // Overwrite, not append: the anchor is the chain's current height, and a
        // file of successive heights would need its own tail to be trusted, which
        // is the problem it exists to solve.
        set_error_handler(static fn(): bool => true);

        try {
            $written = file_put_contents($this->headPath(), $body, LOCK_EX);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            throw EvidenceWriteFailedException::headNotWritable($this->headPath());
        }
    }

    /**
     * The anchor file beside the register. Named in operator-facing output for the
     * reason {@see path()} is: an operator archiving a register has to move both,
     * and a register moved without its anchor reads as truncated.
     */
    public function headPath(): string
    {
        return $this->path . self::HEAD_SUFFIX;
    }

    /**
     * Read the file once and report both what decoded and whether anything did
     * not, so {@see all()} and {@see chainState()} cannot disagree about what the
     * file contains — the reason {@see \Pulsar\Security\Audit\AuditFileSink} reads
     * its tail through a single private method.
     *
     * @return array{records: list<EvidenceRecord>, unreadable: bool}
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return ['records' => [], 'unreadable' => false];
        }

        $contents = file_get_contents($this->path);

        if (!is_string($contents)) {
            // The file exists and could not be read: permissions, a vanished
            // mount, a concurrent truncation. Reporting an empty register would
            // let the chain restart from genesis over records that are still
            // there.
            return ['records' => [], 'unreadable' => true];
        }

        if (trim($contents) === '') {
            return ['records' => [], 'unreadable' => false];
        }

        $records = [];
        $unreadable = false;

        foreach (explode("\n", trim($contents)) as $line) {
            $record = self::decode(trim($line));

            if ($record === null) {
                $unreadable = true;

                continue;
            }

            $records[] = $record;
        }

        return ['records' => $records, 'unreadable' => $unreadable];
    }

    #[Override]
    public function get(string $id): ?EvidenceRecord
    {
        foreach ($this->all() as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    #[Override]
    public function countForControl(string $controlId): int
    {
        return count($this->forControl($controlId));
    }

    /**
     * The most recently stored record for a control, or null when there is none.
     *
     * Ordering is the file's own: the store only ever appends, so the last
     * matching line is the last record written. That is what
     * {@see \Pulsar\Compliance\Verification\EvidenceCollectionJob} reads to decide
     * whether the configured evidence interval has elapsed, and reading it from
     * the durable file rather than from process memory is what makes the interval
     * survive a restart.
     */
    public function latestForControl(string $controlId): ?EvidenceRecord
    {
        $matching = $this->forControl($controlId);

        if ($matching === []) {
            return null;
        }

        return $matching[count($matching) - 1];
    }

    /**
     * The most recent records, oldest first, capped at $limit.
     *
     * Oldest-first because {@see EvidenceChain::verifyChain()} walks the linkage
     * forward and a reversed list would report every record as broken.
     *
     * @return list<EvidenceRecord>
     */
    public function recent(int $limit = 100): array
    {
        $all = $this->all();

        if ($limit <= 0 || count($all) <= $limit) {
            return $all;
        }

        return array_values(array_slice($all, -$limit));
    }

    /**
     * @return array<string, mixed>
     */
    private static function encode(EvidenceRecord $record): array
    {
        return [
            'id' => $record->id,
            'control_id' => $record->controlId,
            'type' => $record->type,
            'description' => $record->description,
            'data' => $record->data,
            'collected_at' => $record->collectedAt->format(DateTimeImmutable::ATOM),
            'signature' => $record->signature,
        ];
    }

    private static function decode(string $line): ?EvidenceRecord
    {
        if ($line === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $id = $decoded['id'] ?? null;
        $controlId = $decoded['control_id'] ?? null;
        $type = $decoded['type'] ?? null;
        $description = $decoded['description'] ?? null;
        $collectedAt = $decoded['collected_at'] ?? null;
        /** @var mixed $signature */
        $signature = $decoded['signature'] ?? null;
        /** @var mixed $data */
        $data = $decoded['data'] ?? [];

        if (!is_string($id) || !is_string($controlId) || !is_string($type) || !is_string($description)) {
            return null;
        }

        if (!is_string($collectedAt) || !is_array($data)) {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable($collectedAt);
        } catch (Exception) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return new EvidenceRecord(
            id: $id,
            controlId: $controlId,
            type: $type,
            description: $description,
            data: $data,
            collectedAt: $timestamp,
            signature: is_string($signature) ? $signature : null,
        );
    }

    /**
     * @throws EvidenceWriteFailedException
     */
    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);

        if (is_dir($dir)) {
            return;
        }

        set_error_handler(static fn(): bool => true);

        try {
            $created = mkdir($dir, self::DIR_PERMISSIONS, true);
        } finally {
            restore_error_handler();
        }

        if (!$created && !is_dir($dir)) {
            throw EvidenceWriteFailedException::directoryNotCreated($dir);
        }
    }

    /**
     * The file this store appends to. Named in operator-facing output so the
     * register can be found without reading the wiring.
     */
    public function path(): string
    {
        return $this->path;
    }
}
