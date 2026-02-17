<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Security\FieldAuthorizationResult;

#[CoversClass(FieldAuthorizationResult::class)]
final class FieldAuthorizationResultTest extends TestCase
{
    #[Test]
    public function all_cases_exist(): void
    {
        $cases = FieldAuthorizationResult::cases();

        self::assertCount(3, $cases);
    }

    #[Test]
    #[DataProvider('caseNameProvider')]
    public function case_names(string $expectedName, FieldAuthorizationResult $case): void
    {
        self::assertSame($expectedName, $case->name);
    }

    /**
     * @return iterable<string, array{string, FieldAuthorizationResult}>
     */
    public static function caseNameProvider(): iterable
    {
        yield 'Allowed' => ['Allowed', FieldAuthorizationResult::Allowed];
        yield 'Redacted' => ['Redacted', FieldAuthorizationResult::Redacted];
        yield 'Denied' => ['Denied', FieldAuthorizationResult::Denied];
    }

    #[Test]
    public function cases_are_distinct(): void
    {
        self::assertNotSame(FieldAuthorizationResult::Allowed, FieldAuthorizationResult::Redacted);
        self::assertNotSame(FieldAuthorizationResult::Redacted, FieldAuthorizationResult::Denied);
        self::assertNotSame(FieldAuthorizationResult::Allowed, FieldAuthorizationResult::Denied);
    }
}
