<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Scope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeWideningException;

#[CoversClass(ScopeWideningException::class)]
final class ScopeWideningExceptionTest extends TestCase
{
    #[Test]
    public function implementsContainerExceptionInterface(): void
    {
        $exception = ScopeWideningException::detected(
            'App\\Service',
            Lifetime::Singleton,
            'App\\RequestScoped',
            Lifetime::RequestScope,
        );

        self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    #[Test]
    public function detectedIncludesAllServiceIdentifiers(): void
    {
        $exception = ScopeWideningException::detected(
            'App\\UserRepo',
            Lifetime::Singleton,
            'App\\RequestLogger',
            Lifetime::RequestScope,
        );

        $message = $exception->getMessage();

        self::assertStringContainsString('App\\UserRepo', $message);
        self::assertStringContainsString('App\\RequestLogger', $message);
        self::assertStringContainsString('singleton', $message);
        self::assertStringContainsString('request', $message);
        self::assertStringContainsString('Scope widening', $message);
    }

    #[Test]
    public function detectedWithTenantScopeDependency(): void
    {
        $exception = ScopeWideningException::detected(
            'GlobalCache',
            Lifetime::Singleton,
            'TenantDb',
            Lifetime::TenantScope,
        );

        $message = $exception->getMessage();

        self::assertStringContainsString('tenant', $message);
        self::assertStringContainsString('longer-lived service must not depend', $message);
    }
}
