<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Exception\TenancyException;
use RuntimeException;

#[CoversClass(TenancyException::class)]
final class TenancyExceptionTest extends TestCase
{
    #[Test]
    public function tenantNotResolvedProducesDescriptiveMessage(): void
    {
        $e = TenancyException::tenantNotResolved();

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertStringContainsString('could not be resolved', $e->getMessage());
    }

    #[Test]
    public function tenantNotFoundIncludesIdentifier(): void
    {
        $e = TenancyException::tenantNotFound('acme-corp');

        self::assertStringContainsString('acme-corp', $e->getMessage());
        self::assertStringContainsString('not found', $e->getMessage());
    }

    #[Test]
    public function tenantNotFoundWithEmptyIdentifier(): void
    {
        $e = TenancyException::tenantNotFound('');

        self::assertStringContainsString('""', $e->getMessage());
    }

    #[Test]
    public function invalidConfigurationIncludesReason(): void
    {
        $e = TenancyException::invalidConfiguration('missing tenant database mapping');

        self::assertStringContainsString('missing tenant database mapping', $e->getMessage());
        self::assertStringContainsString('Invalid tenancy configuration', $e->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $e = TenancyException::tenantNotResolved();

        self::assertInstanceOf(RuntimeException::class, $e);
    }
}
