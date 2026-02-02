<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;

#[CoversClass(ValidationResult::class)]
final class ValidationResultTest extends TestCase
{
    #[Test]
    public function emptyResultPasses(): void
    {
        $result = new ValidationResult();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame([], $result->violations);
    }

    #[Test]
    public function resultWithViolationsFails(): void
    {
        $result = new ValidationResult([
            new Violation('email', 'Required.', 'required'),
        ]);

        self::assertFalse($result->passed());
        self::assertTrue($result->failed());
        self::assertCount(1, $result->violations);
    }

    #[Test]
    public function forFieldReturnsMatchingViolations(): void
    {
        $v1 = new Violation('email', 'Required.', 'required');
        $v2 = new Violation('name', 'Required.', 'required');
        $v3 = new Violation('email', 'Must be valid.', 'email');

        $result = new ValidationResult([$v1, $v2, $v3]);

        $emailViolations = $result->forField('email');
        self::assertCount(2, $emailViolations);
        self::assertSame($v1, $emailViolations[0]);
        self::assertSame($v3, $emailViolations[1]);

        self::assertSame([], $result->forField('nonexistent'));
    }

    #[Test]
    public function firstForFieldReturnsFirstMatch(): void
    {
        $v1 = new Violation('email', 'Required.', 'required');
        $v2 = new Violation('email', 'Must be valid.', 'email');

        $result = new ValidationResult([$v1, $v2]);

        self::assertSame($v1, $result->firstForField('email'));
        self::assertNull($result->firstForField('nonexistent'));
    }

    #[Test]
    public function toArrayReturnsSerializedViolations(): void
    {
        $result = new ValidationResult([
            new Violation('email', 'Required.', 'required'),
            new Violation('name', 'Too short.', 'min_length'),
        ]);

        self::assertSame([
            ['field' => 'email', 'message' => 'Required.', 'rule' => 'required'],
            ['field' => 'name', 'message' => 'Too short.', 'rule' => 'min_length'],
        ], $result->toArray());
    }
}
