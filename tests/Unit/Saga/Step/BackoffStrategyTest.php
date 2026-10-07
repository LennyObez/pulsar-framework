<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Step\BackoffStrategy;

#[CoversNothing]
final class BackoffStrategyTest extends TestCase
{
    #[Test]
    public function test_linear_case_value(): void
    {
        self::assertSame('linear', BackoffStrategy::Linear->value);
    }

    #[Test]
    public function test_exponential_case_value(): void
    {
        self::assertSame('exponential', BackoffStrategy::Exponential->value);
    }

    #[Test]
    public function test_from_valid_string(): void
    {
        self::assertSame(BackoffStrategy::Linear, BackoffStrategy::from('linear'));
        self::assertSame(BackoffStrategy::Exponential, BackoffStrategy::from('exponential'));
    }

    #[Test]
    public function test_tryFrom_invalid_string_returns_null(): void
    {
        /** @var string $invalid */
        $invalid = 'invalid';

        self::assertNull(BackoffStrategy::tryFrom($invalid));
    }
}
