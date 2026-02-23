<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Exception\TenantContextMismatchException;

#[CoversClass(TenantContextMismatchException::class)]
final class TenantContextMismatchExceptionTest extends TestCase
{
    #[Test]
    public function test_detected_message(): void
    {
        $exception = TenantContextMismatchException::detected('acme', 'other');

        self::assertSame('Tenant context mismatch: expected acme, got other', $exception->getMessage());
    }
}
