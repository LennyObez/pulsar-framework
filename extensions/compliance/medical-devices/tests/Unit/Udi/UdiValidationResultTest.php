<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\UdiValidationResult;

#[CoversClass(UdiValidationResult::class)]
final class UdiValidationResultTest extends TestCase
{
    #[Test]
    public function validResultHasNoMessage(): void
    {
        $result = new UdiValidationResult(valid: true);

        self::assertTrue($result->valid);
        self::assertNull($result->message);
    }

    #[Test]
    public function invalidResultHasMessage(): void
    {
        $result = new UdiValidationResult(valid: false, message: 'Invalid UDI format');

        self::assertFalse($result->valid);
        self::assertSame('Invalid UDI format', $result->message);
    }
}
