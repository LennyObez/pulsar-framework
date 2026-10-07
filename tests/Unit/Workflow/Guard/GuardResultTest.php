<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Guard\GuardResult;

#[CoversClass(GuardResult::class)]
final class GuardResultTest extends TestCase
{
    #[Test]
    public function test_allow_creates_allowed_result(): void
    {
        $result = GuardResult::allow();

        self::assertTrue($result->isAllowed());
        self::assertFalse($result->isDenied());
    }

    #[Test]
    public function test_allow_has_empty_reason(): void
    {
        $result = GuardResult::allow();

        self::assertSame('', $result->reason);
    }

    #[Test]
    public function test_deny_creates_denied_result(): void
    {
        $result = GuardResult::deny('Insufficient permissions');

        self::assertFalse($result->isAllowed());
        self::assertTrue($result->isDenied());
    }

    #[Test]
    public function test_deny_stores_reason(): void
    {
        $result = GuardResult::deny('Actor lacks admin role');

        self::assertSame('Actor lacks admin role', $result->reason);
    }

    #[Test]
    public function test_allowed_field_is_true_for_allow(): void
    {
        $result = GuardResult::allow();

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function test_allowed_field_is_false_for_deny(): void
    {
        $result = GuardResult::deny('reason');

        self::assertFalse($result->allowed);
    }
}
