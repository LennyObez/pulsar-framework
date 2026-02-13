<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Security\ServiceIdentity;

#[CoversClass(ServiceIdentity::class)]
final class ServiceIdentityTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $identity = new ServiceIdentity(
            name: 'payment-service',
            trustLevel: 'internal',
            allowedMethods: ['/billing.Invoice/Create', '/billing.Invoice/Get'],
        );

        self::assertSame('payment-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertSame(['/billing.Invoice/Create', '/billing.Invoice/Get'], $identity->allowedMethods);
    }

    #[Test]
    public function permissionsPropertyExposesServicePermission(): void
    {
        $identity = new ServiceIdentity(
            name: 'svc',
            trustLevel: 'internal',
            allowedMethods: ['/a.Service/Method'],
        );

        self::assertTrue($identity->permissions->allows('/a.Service/Method'));
        self::assertFalse($identity->permissions->allows('/b.Service/Other'));
    }

    #[Test]
    public function isMethodAllowedReturnsTrueForWildcard(): void
    {
        $identity = new ServiceIdentity(
            name: 'admin-service',
            trustLevel: 'internal',
            allowedMethods: ['*'],
        );

        self::assertTrue($identity->isMethodAllowed('/any.Service/AnyMethod'));
        self::assertTrue($identity->isMethodAllowed('/another.Service/Method'));
    }

    #[Test]
    public function isMethodAllowedReturnsTrueForExplicitlyAllowedMethod(): void
    {
        $identity = new ServiceIdentity(
            name: 'reader-service',
            trustLevel: 'external',
            allowedMethods: ['/catalog.Products/List', '/catalog.Products/Get'],
        );

        self::assertTrue($identity->isMethodAllowed('/catalog.Products/List'));
        self::assertTrue($identity->isMethodAllowed('/catalog.Products/Get'));
    }

    #[Test]
    public function isMethodAllowedReturnsFalseForDisallowedMethod(): void
    {
        $identity = new ServiceIdentity(
            name: 'reader-service',
            trustLevel: 'external',
            allowedMethods: ['/catalog.Products/List'],
        );

        self::assertFalse($identity->isMethodAllowed('/catalog.Products/Delete'));
        self::assertFalse($identity->isMethodAllowed('/admin.Users/List'));
    }

    #[Test]
    public function isMethodAllowedReturnsFalseForEmptyAllowedMethods(): void
    {
        $identity = new ServiceIdentity(
            name: 'restricted-service',
            trustLevel: 'untrusted',
            allowedMethods: [],
        );

        self::assertFalse($identity->isMethodAllowed('/any.Service/Method'));
    }

    #[Test]
    public function wildcardMustBeExactSingleElementList(): void
    {
        $identity = new ServiceIdentity(
            name: 'mixed-service',
            trustLevel: 'internal',
            allowedMethods: ['*', '/extra.Service/Method'],
        );

        // Wildcard only works as a solo ["*"] — mixed list falls through to in_array
        self::assertTrue($identity->isMethodAllowed('*'));
        self::assertTrue($identity->isMethodAllowed('/extra.Service/Method'));
        self::assertFalse($identity->isMethodAllowed('/other.Service/Method'));
    }
}
