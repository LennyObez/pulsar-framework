<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Validation\UrlValidationResult;

#[CoversClass(UrlValidationResult::class)]
final class UrlValidationResultTest extends TestCase
{
    public function testAllowed(): void
    {
        $result = UrlValidationResult::allowed();

        self::assertTrue($result->safe);
        self::assertSame('', $result->reason);
    }

    public function testRejected(): void
    {
        $result = UrlValidationResult::rejected('URL points to private IP');

        self::assertFalse($result->safe);
        self::assertSame('URL points to private IP', $result->reason);
    }

    public function testRejectedWithEmptyReason(): void
    {
        $result = UrlValidationResult::rejected('');

        self::assertFalse($result->safe);
        self::assertSame('', $result->reason);
    }
}
