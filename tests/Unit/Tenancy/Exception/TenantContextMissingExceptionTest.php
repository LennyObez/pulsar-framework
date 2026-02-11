<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Exception\TenantContextMissingException;

#[CoversClass(TenantContextMissingException::class)]
final class TenantContextMissingExceptionTest extends TestCase
{
    #[Test]
    public function test_for_operation_message(): void
    {
        $exception = TenantContextMissingException::forOperation('database query');

        self::assertSame('Tenant context required for operation: database query', $exception->getMessage());
    }
}
