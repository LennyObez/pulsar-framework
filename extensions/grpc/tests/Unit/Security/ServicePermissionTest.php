<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Security\ServicePermission;

#[CoversClass(ServicePermission::class)]
final class ServicePermissionTest extends TestCase
{
    #[Test]
    public function unrestrictedAllowsAnyMethod(): void
    {
        $permission = ServicePermission::unrestricted();

        self::assertTrue($permission->allows('/any.Service/AnyMethod'));
        self::assertTrue($permission->allows('/other.Service/Other'));
    }

    #[Test]
    public function unrestrictedIsMarkedAsUnrestricted(): void
    {
        $permission = ServicePermission::unrestricted();

        self::assertTrue($permission->isUnrestricted());
        self::assertSame(['*'], $permission->methods());
    }

    #[Test]
    public function restrictedAllowsOnlyListedMethods(): void
    {
        $permission = ServicePermission::restricted([
            '/billing.Invoice/Create',
            '/billing.Invoice/Get',
        ]);

        self::assertTrue($permission->allows('/billing.Invoice/Create'));
        self::assertTrue($permission->allows('/billing.Invoice/Get'));
        self::assertFalse($permission->allows('/billing.Invoice/Delete'));
        self::assertFalse($permission->allows('/admin.Users/List'));
    }

    #[Test]
    public function restrictedIsNotMarkedAsUnrestricted(): void
    {
        $permission = ServicePermission::restricted(['/some.Service/Method']);

        self::assertFalse($permission->isUnrestricted());
    }

    #[Test]
    public function emptyRestrictedDeniesAllMethods(): void
    {
        $permission = ServicePermission::restricted([]);

        self::assertFalse($permission->allows('/any.Service/Method'));
        self::assertFalse($permission->isUnrestricted());
        self::assertSame([], $permission->methods());
    }

    #[Test]
    public function methodsReturnsTheConfiguredList(): void
    {
        $methods = ['/a.Service/MethodA', '/b.Service/MethodB'];
        $permission = ServicePermission::restricted($methods);

        self::assertSame($methods, $permission->methods());
    }

    #[Test]
    public function constructorAllowsDirectInstantiation(): void
    {
        $permission = new ServicePermission(['/direct.Service/Method']);

        self::assertTrue($permission->allows('/direct.Service/Method'));
        self::assertFalse($permission->allows('/other.Service/Method'));
    }
}
