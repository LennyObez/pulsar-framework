<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaSqlCanonicalizer;

#[CoversClass(SchemaSqlCanonicalizer::class)]
final class SchemaSqlCanonicalizerTest extends TestCase
{
    #[Test]
    public function trimsWhitespace(): void
    {
        $result = SchemaSqlCanonicalizer::canonicalize(['  SELECT 1  ', '  SELECT 2  ']);
        self::assertSame("SELECT 1\nSELECT 2", $result);
    }

    #[Test]
    public function normalizesLineEndings(): void
    {
        $result = SchemaSqlCanonicalizer::canonicalize(["SELECT 1\r\nFROM t"]);
        self::assertStringNotContainsString("\r", $result);
    }

    #[Test]
    public function stableHashAcrossWhitespace(): void
    {
        $a = SchemaSqlCanonicalizer::canonicalize(['SELECT 1']);
        $b = SchemaSqlCanonicalizer::canonicalize(['  SELECT 1  ']);
        self::assertSame($a, $b);
        self::assertSame(hash('sha256', $a), hash('sha256', $b));
    }

    #[Test]
    public function emptyStatementsFiltered(): void
    {
        $result = SchemaSqlCanonicalizer::canonicalize(['', '  ', 'SELECT 1', '']);
        self::assertSame('SELECT 1', $result);
    }

    #[Test]
    public function emptyInputReturnsEmptyString(): void
    {
        self::assertSame('', SchemaSqlCanonicalizer::canonicalize([]));
    }
}
