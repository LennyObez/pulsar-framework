<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Terminology;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Terminology\ValidationResult;

#[CoversClass(ValidationResult::class)]
final class ValidationResultTest extends TestCase
{
    #[Test]
    public function validResultWithDisplay(): void
    {
        $result = new ValidationResult(
            valid: true,
            display: 'Diabetes mellitus',
        );

        self::assertTrue($result->valid);
        self::assertSame('Diabetes mellitus', $result->display);
        self::assertNull($result->message);
    }

    #[Test]
    public function invalidResultWithMessage(): void
    {
        $result = new ValidationResult(
            valid: false,
            message: 'Code not found in value set',
        );

        self::assertFalse($result->valid);
        self::assertNull($result->display);
        self::assertSame('Code not found in value set', $result->message);
    }

    #[Test]
    public function minimalValidResult(): void
    {
        $result = new ValidationResult(valid: true);

        self::assertTrue($result->valid);
        self::assertNull($result->display);
        self::assertNull($result->message);
    }
}
