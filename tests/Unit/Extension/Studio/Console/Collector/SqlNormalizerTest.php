<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\SqlNormalizer;

use function strlen;

#[CoversClass(SqlNormalizer::class)]
final class SqlNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReplacesStringLiterals(): void
    {
        $sql = "SELECT * FROM users WHERE name = 'Alice'";
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('?', $normalized);
        self::assertStringNotContainsString('Alice', $normalized);
    }

    #[Test]
    public function normalizeReplacesNumericLiterals(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 42 AND score > 99.5';
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringNotContainsString('42', $normalized);
        self::assertStringNotContainsString('99.5', $normalized);
    }

    #[Test]
    public function normalizeCollapsesWhitespace(): void
    {
        $sql = "SELECT *\n  FROM   users\t\tWHERE  id = 1";
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringNotContainsString("\n", $normalized);
        self::assertStringNotContainsString("\t", $normalized);
        self::assertStringNotContainsString('  ', $normalized);
    }

    #[Test]
    public function normalizeLowercasesSqlKeywords(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1 ORDER BY name ASC';
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('select', $normalized);
        self::assertStringContainsString('from', $normalized);
        self::assertStringContainsString('where', $normalized);
        self::assertStringContainsString('order', $normalized);
    }

    #[Test]
    public function identicalQueriesProduceSameFingerprint(): void
    {
        $sql1 = "SELECT * FROM users WHERE name = 'Alice' AND id = 1";
        $sql2 = "SELECT * FROM users WHERE name = 'Bob' AND id = 42";

        self::assertSame(
            SqlNormalizer::fingerprint($sql1),
            SqlNormalizer::fingerprint($sql2),
        );
    }

    #[Test]
    public function differentQueriesProduceDifferentFingerprints(): void
    {
        $sql1 = 'SELECT * FROM users WHERE id = 1';
        $sql2 = 'DELETE FROM users WHERE id = 1';

        self::assertNotSame(
            SqlNormalizer::fingerprint($sql1),
            SqlNormalizer::fingerprint($sql2),
        );
    }

    #[Test]
    public function fingerprintReturnsSha256Hash(): void
    {
        $fingerprint = SqlNormalizer::fingerprint('SELECT 1');

        self::assertSame(64, strlen($fingerprint));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
    }

    #[Test]
    public function detectQueryTypeIdentifiesSelect(): void
    {
        self::assertSame('SELECT', SqlNormalizer::detectQueryType('SELECT * FROM users'));
    }

    #[Test]
    public function detectQueryTypeIdentifiesInsert(): void
    {
        self::assertSame('INSERT', SqlNormalizer::detectQueryType('INSERT INTO users VALUES (1)'));
    }

    #[Test]
    public function detectQueryTypeIdentifiesUpdate(): void
    {
        self::assertSame('UPDATE', SqlNormalizer::detectQueryType('UPDATE users SET name = ?'));
    }

    #[Test]
    public function detectQueryTypeIdentifiesDelete(): void
    {
        self::assertSame('DELETE', SqlNormalizer::detectQueryType('DELETE FROM users WHERE id = 1'));
    }

    #[Test]
    public function detectQueryTypeIdentifiesDdl(): void
    {
        self::assertSame('DDL', SqlNormalizer::detectQueryType('CREATE TABLE users (id INT)'));
        self::assertSame('DDL', SqlNormalizer::detectQueryType('ALTER TABLE users ADD COLUMN name TEXT'));
        self::assertSame('DDL', SqlNormalizer::detectQueryType('DROP TABLE users'));
    }

    #[Test]
    public function detectQueryTypeReturnsOtherForUnknown(): void
    {
        self::assertSame('OTHER', SqlNormalizer::detectQueryType('EXPLAIN SELECT 1'));
    }
}
