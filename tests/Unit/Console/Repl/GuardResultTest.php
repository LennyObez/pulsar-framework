<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\GuardResult;

#[CoversClass(GuardResult::class)]
final class GuardResultTest extends TestCase
{
    #[Test]
    public function allowedResult(): void
    {
        $result = new GuardResult(
            allowed: true,
            reason: 'Access granted.',
        );

        self::assertTrue($result->allowed);
        self::assertSame('Access granted.', $result->reason);
        self::assertFalse($result->isProductionOverride);
    }

    #[Test]
    public function blockedResult(): void
    {
        $result = new GuardResult(
            allowed: false,
            reason: 'REPL is disabled.',
        );

        self::assertFalse($result->allowed);
        self::assertSame('REPL is disabled.', $result->reason);
    }

    #[Test]
    public function productionOverride(): void
    {
        $result = new GuardResult(
            allowed: true,
            reason: 'Production override.',
            isProductionOverride: true,
        );

        self::assertTrue($result->allowed);
        self::assertTrue($result->isProductionOverride);
    }

    #[Test]
    public function defaultProductionOverrideIsFalse(): void
    {
        $result = new GuardResult(
            allowed: true,
            reason: 'OK',
        );

        self::assertFalse($result->isProductionOverride);
    }
}
