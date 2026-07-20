<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Security;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Analytics\Contracts\VisitorSaltStoreInterface;

use function bin2hex;
use function random_bytes;

/**
 * Database-backed {@see VisitorSaltStoreInterface}.
 *
 * The salt lives in a durable table rather than the cache on purpose: a cache
 * eviction mid-day would regenerate a different salt and split one visitor
 * across two hashes, inflating unique-visitor counts. The salt is stored as
 * plaintext random bytes — encrypting it with the master key would re-couple
 * old data to that long-lived key and defeat the forward secrecy that deleting
 * the row provides.
 */
#[Internal(reason: 'Analytics security internals; use via VisitorSaltStoreInterface')]
final readonly class DbVisitorSaltStore implements VisitorSaltStoreInterface
{
    /** 32 random bytes, hex-encoded to 64 chars. */
    private const int SALT_BYTES = 32;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function saltForDay(int $dayNumber): string
    {
        $existing = $this->existingSaltForDay($dayNumber);

        if ($existing !== null) {
            return $existing;
        }

        $salt = bin2hex(random_bytes(self::SALT_BYTES));
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // Insert-or-ignore, then re-read: whichever concurrent writer wins the
        // unique day_number key, every caller ends up returning that single
        // stored salt rather than the value it locally generated.
        $this->connection->execute($this->insertIgnoreSql(), [
            'day_number' => $dayNumber,
            'salt' => $salt,
            'created_at' => $createdAt,
        ]);

        return $this->existingSaltForDay($dayNumber) ?? $salt;
    }

    public function existingSaltForDay(int $dayNumber): ?string
    {
        $row = $this->connection->query(
            'SELECT salt FROM analytics_visitor_salts WHERE day_number = :day_number',
            ['day_number' => $dayNumber],
        )->first();

        return $row?->getNullableString('salt');
    }

    public function purgeOlderThan(int $cutoffDayNumber): int
    {
        return $this->connection->execute(
            'DELETE FROM analytics_visitor_salts WHERE day_number < :cutoff',
            ['cutoff' => $cutoffDayNumber],
        );
    }

    /**
     * Driver-specific "insert unless the day already has a salt" statement.
     * The re-read in {@see saltForDay()} makes the exact conflict clause
     * immaterial as long as a duplicate key does not raise.
     */
    private function insertIgnoreSql(): string
    {
        $columns = '(day_number, salt, created_at) VALUES (:day_number, :salt, :created_at)';

        return match ($this->connection->driver()) {
            Driver::MySQL => "INSERT IGNORE INTO analytics_visitor_salts {$columns}",
            Driver::PostgreSQL, Driver::SQLite =>
                "INSERT INTO analytics_visitor_salts {$columns} ON CONFLICT (day_number) DO NOTHING",
        };
    }
}
