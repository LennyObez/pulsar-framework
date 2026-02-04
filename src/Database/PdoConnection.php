<?php

declare(strict_types=1);

namespace Pulsar\Database;

use function is_bool;
use function is_int;

use NoDiscard;
use Override;
use PDO;
use PDOException;
use PDOStatement;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Database\Exception\DatabaseException;

use function sprintf;

use Throwable;

/**
 * PDO-based database connection with lazy initialization.
 *
 * The actual PDO instance is created on first use, not at construction.
 */
final class PdoConnection implements ConnectionInterface
{
    private ?PDO $connection = null;
    private int $transactionDepth = 0;

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
        $dsn = $config->driver->buildDsn($config->host, $config->port, $config->database, $config->charset);

        /** @var array<int, mixed> $pdoOptions */
        $pdoOptions = $config->options;

        return new self(
            connectionName: $config->name,
            driver: $config->driver,
            dsn: $dsn,
            username: $config->username !== '' ? $config->username : null,
            password: $config->password !== '' ? $config->password : null,
            options: $pdoOptions,
        );
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

        return new Transaction($pdo, $depth);
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
            $this->transactionDepth--;

            return $result;
        } catch (Throwable $e) {
            if ($transaction->active) {
                $transaction->rollback();
            }
            $this->transactionDepth--;

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

            $mergedOptions = $defaultOptions;
            foreach ($this->options as $key => $value) {
                $mergedOptions[$key] = $value;
            }

            $this->connection = new PDO(
                $this->dsn,
                $this->username,
                $this->password,
                $mergedOptions,
            );
        } catch (PDOException $e) {
            throw DatabaseException::connectionFailed($this->connectionName, $e);
        }

        return $this->connection;
    }

    /**
     * Bind values to a PDO statement with appropriate types.
     *
     * @param PDOStatement $stmt
     * @param array<string, mixed> $bindings
     */
    private function bindValues(PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
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
