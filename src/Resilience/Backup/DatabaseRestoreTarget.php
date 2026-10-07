<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\SqlIdentifier;
use Throwable;

use function array_combine;
use function array_keys;
use function array_map;
use function array_pop;
use function base64_decode;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Puts the rows from a `database/*.ndjson` entry back into their table.
 *
 * IT REFUSES A TABLE THAT ALREADY HAS ROWS, unless the operator said to replace
 * them. That default is the difference between a restore and a corruption: an
 * append into a populated table produces duplicate keys at best and a silently
 * doubled ledger at worst, and the operator who reaches for a restore during an
 * incident is the least likely person to be checking. So the target fails closed,
 * names the table, and tells the operator which flag says "yes, replace it".
 *
 * `--replace` DELETEs the table's rows rather than TRUNCATEing them. Truncation
 * is faster and is implicitly committed on MySQL, which would put the restore
 * outside the transaction that is meant to make it all-or-nothing; a restore that
 * cannot be rolled back is one where a failure halfway leaves the table empty.
 *
 * ORDER IS THE OPERATOR'S PROBLEM AND IS NAMED AS SUCH. Rows are inserted table
 * by table in archive order, which is alphabetical, and that will violate a
 * foreign key whose parent sorts after its child. `docs/backup.md` states the
 * remedy — restore with constraint checks deferred, which is a session setting
 * this class deliberately does not reach in and change on the operator's behalf.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DatabaseRestoreTarget implements RestoreTargetInterface
{
    /** Rows per INSERT round trip. */
    public const int BATCH_SIZE = 200;

    /** The source id whose entries this target claims. */
    private const string SOURCE_PREFIX = 'database/';

    public function __construct(
        private ConnectionInterface $connection,
        /**
         * Whether an already-populated table may have its rows deleted first.
         * False fails closed; see the class note.
         */
        private bool $replaceExisting = false,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'database';
    }

    #[Override]
    public function accepts(string $entryName): bool
    {
        return str_starts_with($entryName, self::SOURCE_PREFIX) && str_ends_with($entryName, '.ndjson');
    }

    /**
     * @param iterable<string> $chunks
     *
     * @return int<0, max>
     *
     * @throws BackupException
     */
    #[Override]
    public function restore(string $entryName, iterable $chunks): int
    {
        $consumed = 0;
        $pending = '';
        $table = null;
        $batch = [];

        foreach ($chunks as $chunk) {
            $consumed += strlen($chunk);
            $pending .= $chunk;

            $lines = explode("\n", $pending);
            // The last element is either an empty string (the chunk ended on a
            // newline) or a partial line that the next chunk completes.
            $pending = (string) array_pop($lines);

            foreach ($lines as $line) {
                $this->consumeLine($entryName, $line, $table, $batch);
            }
        }

        if (trim($pending) !== '') {
            $this->consumeLine($entryName, $pending, $table, $batch);
        }

        if ($table === null) {
            throw BackupException::restoreFailed(
                $entryName,
                'the entry carries no table header, so there is nothing to say where its rows belong',
            );
        }

        if ($batch !== []) {
            $this->insert($entryName, $table, $batch);
        }

        return $consumed;
    }

    /**
     * @param list<array<string, mixed>> $batch
     *
     * @throws BackupException
     */
    private function consumeLine(string $entryName, string $line, ?string &$table, array &$batch): void
    {
        if (trim($line) === '') {
            return;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw BackupException::restoreFailed($entryName, 'a line is not JSON: ' . $failure->getMessage(), $failure);
        }

        if (!is_array($decoded)) {
            throw BackupException::restoreFailed($entryName, 'a line is not a JSON object');
        }

        if (($decoded['pulsar_entry'] ?? null) === 'table') {
            $name = $decoded['table'] ?? null;

            if (!is_string($name) || $name === '') {
                throw BackupException::restoreFailed($entryName, 'its header names no table');
            }

            $table = $this->prepare($entryName, $name);

            return;
        }

        $row = $decoded['row'] ?? null;

        if (!is_array($row)) {
            throw BackupException::restoreFailed($entryName, 'a line is neither a header nor a row');
        }

        if ($table === null) {
            throw BackupException::restoreFailed($entryName, 'a row appears before the entry names its table');
        }

        /** @var array<string, mixed> $row */
        $batch[] = self::decodeValues($entryName, $row);

        if (count($batch) >= self::BATCH_SIZE) {
            $this->insert($entryName, $table, $batch);
            $batch = [];
        }
    }

    /**
     * Validate the table, and clear it if the operator authorised that.
     *
     * @throws BackupException
     */
    private function prepare(string $entryName, string $table): string
    {
        try {
            $quoted = SqlIdentifier::quote($table, $this->connection->driver());
        } catch (Throwable $failure) {
            throw BackupException::restoreFailed($entryName, $failure->getMessage(), $failure);
        }

        try {
            $existing = $this->connection->query(sprintf('SELECT COUNT(*) AS total FROM %s', $quoted));
        } catch (Throwable $failure) {
            throw BackupException::restoreFailed(
                $entryName,
                sprintf(
                    'table "%s" cannot be read, so the restore cannot tell whether it would '
                        . 'overwrite anything: %s. Run the deployment\'s migrations first',
                    $table,
                    $failure->getMessage(),
                ),
                $failure,
            );
        }

        $rows = $existing->first()?->getInt('total') ?? 0;

        if ($rows === 0) {
            return $quoted;
        }

        if (!$this->replaceExisting) {
            throw BackupException::restoreFailed(
                $entryName,
                sprintf(
                    'table "%s" already holds %d row(s). Restoring into it would merge two data '
                        . 'sets rather than recover one; re-run with --replace to delete them first, '
                        . 'or restore into an empty database',
                    $table,
                    $rows,
                ),
            );
        }

        try {
            $this->connection->execute(sprintf('DELETE FROM %s', $quoted));
        } catch (Throwable $failure) {
            throw BackupException::restoreFailed($entryName, $failure->getMessage(), $failure);
        }

        return $quoted;
    }

    /**
     * @param list<array<string, mixed>> $batch
     *
     * @throws BackupException
     */
    private function insert(string $entryName, string $quotedTable, array $batch): void
    {
        foreach ($batch as $row) {
            if ($row === []) {
                continue;
            }

            $columns = array_keys($row);
            $delimiter = SqlIdentifier::delimiter($this->connection->driver());

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $quotedTable,
                implode(', ', array_map(
                    static fn(string $column): string => $delimiter . SqlIdentifier::validate($column) . $delimiter,
                    $columns,
                )),
                implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)),
            );

            try {
                $this->connection->execute($sql, $row);
            } catch (Throwable $failure) {
                throw BackupException::restoreFailed($entryName, $failure->getMessage(), $failure);
            }
        }
    }

    /**
     * Turn the binary markers {@see DatabaseBackupSource} wrote back into bytes.
     *
     * Mapped over the column NAMES rather than over the values, which is what
     * changed when the decoder started refusing: a marker that does not decode has
     * to be named, and a mapper handed only the value cannot say which column it
     * was. Still a map and not a `foreach` that fills an offset -- a column value
     * has no type this class can know, and assigning one into an array offset is
     * the one shape that cannot be stated honestly to the analysers.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     *
     * @throws BackupException
     */
    private static function decodeValues(string $entryName, array $row): array
    {
        $columns = array_keys($row);

        return array_combine(
            $columns,
            array_map(
                static fn(string $column): mixed => self::decodeValue($entryName, $column, $row[$column]),
                $columns,
            ),
        );
    }

    /**
     * One column value, with a binary marker turned back into the bytes it stands for.
     *
     * A MARKER THAT DOES NOT DECODE REFUSES THE RESTORE. This is the one corruption
     * nothing else in the module can see: the seal proves the archive was not
     * modified after it was written and the per-entry digest proves the bytes are
     * the bytes the producer handed over, so an entry whose base64 is malformed --
     * a producer that encoded wrongly, a hand-edited entry re-sealed by a holder of
     * the key -- passes both. Substituting the empty string, which is what this did,
     * would put a blank where a signature, a document or a key had been, in the one
     * operation nobody re-reads afterwards. A restore that stops and names the
     * column is recoverable; a ledger with a silently emptied column is not.
     *
     * @throws BackupException
     */
    private static function decodeValue(string $entryName, string $column, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        /** @var mixed $marker */
        $marker = $value[DatabaseBackupSource::BINARY_MARKER] ?? null;

        if (! is_string($marker)) {
            return $value;
        }

        $bytes = base64_decode($marker, true);

        if ($bytes === false) {
            throw BackupException::restoreFailed(
                $entryName,
                sprintf(
                    'column "%s" carries a %s marker that is not base64, so the bytes it stands '
                        . 'for cannot be recovered. Nothing was written: restoring it as an empty '
                        . 'value would put a blank where that column had content',
                    $column,
                    DatabaseBackupSource::BINARY_MARKER,
                ),
            );
        }

        return $bytes;
    }
}
