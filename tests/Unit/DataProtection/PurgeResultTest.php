<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\PurgeResult;

#[CoversClass(PurgeResult::class)]
final class PurgeResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new PurgeResult(
            category: 'audit_logs',
            purgedCount: 42,
            dryRun: false,
            durationMs: 123.45,
        );

        self::assertSame('audit_logs', $result->category);
        self::assertSame(42, $result->purgedCount);
        self::assertFalse($result->dryRun);
        self::assertSame(123.45, $result->durationMs);
    }

    #[Test]
    public function dryRunResult(): void
    {
        $result = new PurgeResult(
            category: 'sessions',
            purgedCount: 100,
            dryRun: true,
            durationMs: 0.5,
        );

        self::assertTrue($result->dryRun);
        self::assertSame(100, $result->purgedCount);
    }
}
