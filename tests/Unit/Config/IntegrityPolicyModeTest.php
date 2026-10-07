<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityPolicyMode;

#[CoversNothing]
final class IntegrityPolicyModeTest extends TestCase
{
    #[Test]
    public function backingValues(): void
    {
        self::assertSame('warn', IntegrityPolicyMode::Warn->value);
        self::assertSame('strict', IntegrityPolicyMode::Strict->value);
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(2, IntegrityPolicyMode::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(IntegrityPolicyMode::tryFrom('lenient'));
    }
}
