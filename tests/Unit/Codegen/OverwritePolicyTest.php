<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\OverwritePolicy;

#[CoversClass(OverwritePolicy::class)]
final class OverwritePolicyTest extends TestCase
{
    #[Test]
    public function skipHasCorrectValue(): void
    {
        self::assertSame('skip', OverwritePolicy::Skip->value);
    }

    #[Test]
    public function forceHasCorrectValue(): void
    {
        self::assertSame('force', OverwritePolicy::Force->value);
    }

    #[Test]
    public function failHasCorrectValue(): void
    {
        self::assertSame('fail', OverwritePolicy::Fail->value);
    }

    #[Test]
    public function fromStringResolvesAllCases(): void
    {
        self::assertSame(OverwritePolicy::Skip, OverwritePolicy::from('skip'));
        self::assertSame(OverwritePolicy::Force, OverwritePolicy::from('force'));
        self::assertSame(OverwritePolicy::Fail, OverwritePolicy::from('fail'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(OverwritePolicy::tryFrom('unknown'));
    }
}
