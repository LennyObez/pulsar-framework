<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use Generator;
use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;
use Pulsar\Database\Row;
use Pulsar\Database\SqlIdentifier;
use Throwable;

use function array_map;
use function base64_encode;
use function is_resource;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;
use function stream_get_contents;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Every user table in a connection, one newline-delimited JSON entry per table.
 *
 * WHY NDJSON AND NOT A NATIVE DUMP. `mysqldump` and `pg_dump` produce better
 * dumps than this ever will, and a deployment that has them should keep using
 * them — `docs/backup.md` says so. What they cannot do is run inside the PHP
 * process from a `pulsar` command with no shell, no second binary on the image,
 * and no credentials on a command line; a framework primitive that requires an
 * external tool is a primitive most deployments do not have. NDJSON is the format
 * that survives that constraint: one row per line, restorable a line at a time,
 * readable by a human during an incident, and streamable in both directions.
 *
 * WHAT IT COPIES, exactly: rows. Not schema, not indexes, not sequences, not
 * grants, not triggers. The schema is a versioned artefact of the deployment and
 * is restored by running migrations, which is a stronger guarantee than restoring
 * a snapshot of DDL that may predate the code being deployed beside it — see
 * `docs/backup.md`, which states the recovery order (deploy the commit, migrate,
 * then restore rows) rather than leaving an operator to infer it.
 *
 * BATCHING AND ORDERING. Rows are read in batches of {@see BATCH_SIZE} with
 * `LIMIT`/`OFFSET`, ordered by the primary key where the table has one. A table
 * with NO primary key is read WITHOUT an `ORDER BY`, which no engine guarantees
 * to be stable across batches; that limit is real, it is stated in the entry's
 * own header line, and the honest remedy is a primary key rather than a comment
 * claiming otherwise.
 *
 * NON-UTF-8 VALUES. A `BLOB` column holds bytes, and bytes are not JSON. A value
 * that is not valid UTF-8 is written as `{"pulsar_b64": "..."}` and read back to
 * the original bytes by {@see DatabaseRestoreTarget}, so a binary column survives
 * the round trip instead of failing the encode or arriving corrupted.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DatabaseBackupSource implements BackupSourceInterface
{
    /** Rows fetched per query. The memory bound of this source. */
    public const int BATCH_SIZE = 500;

    /** Marker key wrapping a value that is not valid UTF-8. */
    public const string BINARY_MARKER = 'pulsar_b64';

    public function __construct(
        private ConnectionInterface $connection,
        private DatabaseIntrospectorInterface $introspector,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'database';
    }

    #[Override]
    public function describe(): string
    {
        return sprintf(
            'every row of every user table on the "%s" connection, as newline-delimited JSON; '
                . 'schema, indexes and grants are not included',
            $this->connection->name(),
        );
    }

    /**
     * @return iterable<BackupEntry>
     *
     * @throws BackupException
     */
    #[Override]
    public function entries(): iterable
    {
        try {
            $tables = $this->introspector->tables();
        } catch (Throwable $failure) {
            throw BackupException::sourceUnreadable($this->id(), $failure->getMessage(), $failure);
        }

        foreach ($tables as $table) {
            yield new BackupEntry($table->name . '.ndjson', $this->rows($table->name));
        }
    }

    /**
     * One JSON line per row, plus a leading header line naming the table.
     *
     * The header is what makes an entry self-describing: a restore reads the
     * table name out of the archive rather than out of the file name, so an
     * archive that has been renamed still restores into the right table, and an
     * operator reading the first line of an entry knows what they are holding.
     *
     * @return Generator<int, string>
     *
     * @throws BackupException
     */
    private function rows(string $table): Generator
    {
        $quoted = SqlIdentifier::quote($table, $this->connection->driver());
        $primaryKey = $this->orderColumn($table);

        yield $this->line([
            'pulsar_entry' => 'table',
            'table' => $table,
            'ordered_by' => $primaryKey,
        ]);

        $order = $primaryKey === null
            ? ''
            : ' ORDER BY ' . SqlIdentifier::quote($primaryKey, $this->connection->driver());

        $offset = 0;

        while (true) {
            $sql = sprintf('SELECT * FROM %s%s LIMIT %d OFFSET %d', $quoted, $order, self::BATCH_SIZE, $offset);

            try {
                $result = $this->connection->query($sql);
            } catch (Throwable $failure) {
                throw BackupException::sourceUnreadable(
                    $this->id(),
                    sprintf('table "%s" could not be read: %s', $table, $failure->getMessage()),
                    $failure,
                );
            }

            if ($result->rows === []) {
                return;
            }

            $batch = '';

            foreach ($result->rows as $row) {
                $batch .= $this->line(['row' => self::encodable($row)]);
            }

            yield $batch;

            if ($result->rowCount < self::BATCH_SIZE) {
                return;
            }

            $offset += self::BATCH_SIZE;
        }
    }

    /**
     * The column to order a table's rows by, or null when it has no primary key.
     */
    private function orderColumn(string $table): ?string
    {
        try {
            return $this->introspector->primaryKey($table);
        } catch (Throwable) {
            // An engine that will not describe the table can still be read from;
            // the entry header records that the order is undefined.
            return null;
        }
    }

    /**
     * Render one JSON line.
     *
     * @param array<string, mixed> $payload
     *
     * @throws BackupException
     */
    private function line(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        } catch (JsonException $failure) {
            throw BackupException::sourceUnreadable($this->id(), $failure->getMessage(), $failure);
        }
    }

    /**
     * A row's values in a form JSON can carry, without losing bytes.
     *
     * @return array<string, mixed>
     */
    private static function encodable(Row $row): array
    {
        // Mapped rather than accumulated: a column's value has no type this class
        // can know, and writing one into an array offset is the one shape that
        // cannot be stated honestly. array_map() preserves the column names.
        return array_map(self::encodableValue(...), $row->data);
    }

    /**
     * One column value in a form JSON can carry.
     */
    private static function encodableValue(mixed $value): mixed
    {
        if (is_resource($value)) {
            // PDO hands large-object columns back as streams on PostgreSQL.
            $read = stream_get_contents($value);
            $value = $read === false ? '' : $read;
        }

        return is_string($value) && preg_match('//u', $value) !== 1
            ? [self::BINARY_MARKER => base64_encode($value)]
            : $value;
    }
}
