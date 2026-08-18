<?php

declare(strict_types=1);

namespace Pulsar\Database;

use NoDiscard;
use Override;
use PDO;
use PDOException;
use PDOStatement;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Dialect\Dialects;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Exception\InvalidDsnComponentException;
use Throwable;

use function array_filter;
use function array_keys;
use function array_replace;
use function array_values;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * PDO-based database connection with lazy initialization.
 *
 * The actual PDO instance is created on first use, not at construction.
 */
final class PdoConnection implements ConnectionInterface
{
    /** Both spellings an operator may reasonably write for the same libpq parameter. */
    private const array SSL_MODE_KEYS = ['sslmode', 'ssl_mode'];

    private ?PDO $connection = null;
    private int $transactionDepth = 0;

    /**
     * Cached because establishing it costs a round trip on MySQL, and because the answer
     * cannot change for the life of a connection.
     */
    private ?DriverVariant $cachedVariant = null;

    public function __construct(
        private readonly string $connectionName,
        private readonly Driver $driver,
        private readonly string $dsn,
        private readonly ?string $username,
        private readonly ?string $password,
        /** @var array<int, mixed> */
        private readonly array $options = [],
    ) {}

    /**
     * Create a PdoConnection from a ConnectionConfig DTO.
     */
    #[NoDiscard]
    public static function fromConfig(ConnectionConfig $config): self
    {
        $sslMode = self::extractSslMode($config);

        $dsn = $config->driver->buildDsn(
            $config->host,
            $config->port,
            $config->database,
            $config->charset,
            $sslMode,
        );

        $pdoOptions = self::assertOnlyDriverAttributes($config);

        return new self(
            connectionName: $config->name,
            driver: $config->driver,
            dsn: $dsn,
            username: $config->username !== '' ? $config->username : null,
            password: $config->password !== '' ? $config->password : null,
            options: $pdoOptions,
        );
    }

    /**
     * Read the operator's TLS intent out of the connection options.
     *
     * `sslmode` is a libpq DSN parameter, not a PDO attribute. Left in the options
     * array it reaches PDO's fourth constructor argument, which indexes by integer
     * attribute constants and drops string keys without a word — so the connection
     * came up in plaintext while the compliance verifier, reading the same array,
     * reported TLS as configured.
     */
    private static function extractSslMode(ConnectionConfig $config): ?string
    {
        foreach (self::SSL_MODE_KEYS as $key) {
            /** @var mixed $value */
            $value = $config->options[$key] ?? null;

            if (is_string($value)) {
                return strtolower($value);
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed> The options PDO will actually read.
     *
     * @throws InvalidDsnComponentException If a string key would be discarded.
     */
    private static function assertOnlyDriverAttributes(ConnectionConfig $config): array
    {
        $discarded = array_values(array_filter(
            array_keys($config->options),
            static fn(int|string $key): bool => is_string($key)
                && !in_array($key, self::SSL_MODE_KEYS, true),
        ));

        if ($discarded !== []) {
            throw InvalidDsnComponentException::discardedOptionKeys($config->name, $discarded);
        }

        /** @var array<int, mixed> $attributes */
        $attributes = [];

        /** @var mixed $value */
        foreach ($config->options as $key => $value) {
            if (is_int($key)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        $pdo = $this->pdo();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindValues($stmt, $bindings);
            $stmt->execute();

            /** @var list<array<string, mixed>> $data */
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Result::fromArrays($data);
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed($sql, $e);
        }
    }

    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        $pdo = $this->pdo();

        try {
            $stmt = $pdo->prepare($sql);
            $this->bindValues($stmt, $bindings);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed($sql, $e);
        }
    }

    #[Override]
    public function prepare(string $sql): Statement
    {
        $pdo = $this->pdo();

        try {
            $stmt = $pdo->prepare($sql);

            return new Statement($stmt, $sql);
        } catch (PDOException $e) {
            throw DatabaseException::prepareError($sql, $e);
        }
    }

    #[Override]
    public function beginTransaction(): Transaction
    {
        $pdo = $this->pdo();
        $depth = $this->transactionDepth;

        try {
            if ($depth === 0) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec(sprintf('SAVEPOINT pulsar_sp_%d', $depth));
            }
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed('BEGIN TRANSACTION/SAVEPOINT', $e);
        }

        $this->transactionDepth++;

        return new Transaction($pdo, $depth, function (): void {
            $this->transactionDepth--;
        });
    }

    /**
     * @throws DatabaseException
     * @throws Throwable
     */
    #[Override]
    public function transaction(callable $callback): mixed
    {
        $transaction = $this->beginTransaction();

        try {
            $result = $callback($this);
            $transaction->commit();

            return $result;
        } catch (Throwable $e) {
            if ($transaction->active) {
                try {
                    $transaction->rollback();
                } catch (Throwable $rollbackFailure) {
                    // Attached, never substituted. The rollback used to throw straight
                    // out of here and take the original failure with it, so an operator
                    // whose migration had died on a real error was handed a message about
                    // savepoints instead — and the reason the work was abandoned was gone.
                    throw DatabaseException::rollbackFailed($rollbackFailure->getMessage(), $e);
                }
            }

            throw $e;
        }
    }

    #[Override]
    public function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId() ?: '0';
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->driver;
    }

    /**
     * Ask the server which member of its family it is, once.
     *
     * Only the MySQL driver has more than one member, and only the server's own
     * `VERSION()` string distinguishes them — the DSN, the port and the client library
     * are identical for MySQL, MariaDB and Percona. SQLite and PostgreSQL answer without
     * a round trip because there is nothing to ask.
     *
     * A failure to read the version answers Standard rather than throwing. The variant
     * refines a dialect; it is not a precondition for connecting, and a server that
     * cannot answer `SELECT VERSION()` will fail on the caller's own query a moment later
     * with a far more useful message than one raised here.
     */
    #[Override]
    public function variant(): DriverVariant
    {
        if ($this->driver !== Driver::MySQL) {
            return DriverVariant::Standard;
        }

        if ($this->cachedVariant === null) {
            try {
                $result = $this->query('SELECT VERSION() AS version');

                $this->cachedVariant = $result->rows === []
                    ? DriverVariant::Standard
                    : DriverVariant::detect($result->rows[0]->getString('version'));
            } catch (DatabaseException) {
                $this->cachedVariant = DriverVariant::Standard;
            }
        }

        return $this->cachedVariant;
    }

    #[Override]
    public function dialect(): DialectInterface
    {
        return Dialects::for($this->driver, $this->variant());
    }

    #[Override]
    public function name(): string
    {
        return $this->connectionName;
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    #[Override]
    public function disconnect(): void
    {
        $this->connection = null;
        $this->transactionDepth = 0;
    }

    /**
     * Get or create the PDO instance (lazy initialization).
     *
     * @throws DatabaseException
     */
    private function pdo(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        try {
            $defaultOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            // array_replace, never spread. PDO's option constants are integers, and array
            // unpacking RENUMBERS integer keys from zero — so a spread turns
            // [ATTR_ERRMODE => …, ATTR_DEFAULT_FETCH_MODE => …, ATTR_EMULATE_PREPARES => …]
            // into [0 => …, 1 => …, 2 => …], which PDO reads as ATTR_AUTOCOMMIT,
            // ATTR_PREFETCH and ATTR_TIMEOUT. Every intended option is silently lost and
            // three unintended ones are set, with no error to say so.
            $mergedOptions = array_replace($defaultOptions, $this->options);

            $this->connection = new PDO(
                $this->dsn,
                $this->username,
                $this->password,
                $mergedOptions,
            );

            if ($this->driver === Driver::SQLite) {
                $this->connection->exec('PRAGMA foreign_keys = ON');
            }
        } catch (PDOException $e) {
            throw DatabaseException::connectionFailed($this->connectionName, $e);
        }

        return $this->connection;
    }

    /**
     * Bind values to a PDO statement with appropriate types.
     *
     * Detects Param value objects and uses their declared PDO type
     * (e.g., PDO::PARAM_LOB for binary data) for portable binding.
     *
     * @param PDOStatement $stmt
     * @param array<string, mixed> $bindings
     */
    private function bindValues(PDOStatement $stmt, array $bindings): void
    {
        /** @var mixed $value */
        foreach ($bindings as $key => $value) {
            if ($value instanceof Param) {
                $stmt->bindValue(':' . ltrim($key, ':'), $value->bytes(), $value->pdoType());

                continue;
            }

            $paramType = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            };

            $stmt->bindValue(':' . ltrim($key, ':'), $value, $paramType);
        }
    }
}
