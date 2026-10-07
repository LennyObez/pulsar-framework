<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Collector;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\SqlNormalizer;

use function strlen;

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
        $sql = 'SELECT * FROM users WHERE id = 42';
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringNotContainsString('42', $normalized);
    }

    #[Test]
    public function normalizeCollapsesWhitespace(): void
    {
        $sql = "SELECT  *   FROM\n  users\t WHERE id = 1";
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringNotContainsString("\n", $normalized);
        self::assertStringNotContainsString("\t", $normalized);
        self::assertStringNotContainsString('  ', $normalized);
    }

    #[Test]
    public function normalizeLowercasesKeywords(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $normalized = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('select', $normalized);
        self::assertStringContainsString('from', $normalized);
        self::assertStringContainsString('where', $normalized);
    }

    #[Test]
    public function fingerprintReturnsSha256Hash(): void
    {
        $fingerprint = SqlNormalizer::fingerprint('SELECT * FROM users WHERE id = 1');

        self::assertSame(64, strlen($fingerprint));
        self::assertTrue(ctype_xdigit($fingerprint));
    }

    #[Test]
    public function fingerprintIsDeterministic(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 42';
        $fp1 = SqlNormalizer::fingerprint($sql);
        $fp2 = SqlNormalizer::fingerprint($sql);

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function fingerprintIsSameForStructurallyIdenticalQueries(): void
    {
        $fp1 = SqlNormalizer::fingerprint('SELECT * FROM users WHERE id = 1');
        $fp2 = SqlNormalizer::fingerprint('SELECT * FROM users WHERE id = 999');

        self::assertSame($fp1, $fp2);
    }

    #[Test]
    public function detectQueryTypeReturnsSelect(): void
    {
        self::assertSame('SELECT', SqlNormalizer::detectQueryType('SELECT * FROM users'));
    }

    #[Test]
    public function detectQueryTypeReturnsInsert(): void
    {
        self::assertSame('INSERT', SqlNormalizer::detectQueryType('INSERT INTO users VALUES (1)'));
    }

    #[Test]
    public function detectQueryTypeReturnsUpdate(): void
    {
        self::assertSame('UPDATE', SqlNormalizer::detectQueryType('UPDATE users SET name = ?'));
    }

    #[Test]
    public function detectQueryTypeReturnsDelete(): void
    {
        self::assertSame('DELETE', SqlNormalizer::detectQueryType('DELETE FROM users WHERE id = 1'));
    }

    #[Test]
    public function detectQueryTypeReturnsDdl(): void
    {
        self::assertSame('DDL', SqlNormalizer::detectQueryType('CREATE TABLE users (id INT)'));
        self::assertSame('DDL', SqlNormalizer::detectQueryType('ALTER TABLE users ADD COLUMN name'));
        self::assertSame('DDL', SqlNormalizer::detectQueryType('DROP TABLE users'));
    }

    #[Test]
    public function detectQueryTypeReturnsOtherForUnknown(): void
    {
        self::assertSame('OTHER', SqlNormalizer::detectQueryType('PRAGMA table_info(users)'));
    }
}
