<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Security;

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
        $perms = ServicePermission::unrestricted();

        self::assertTrue($perms->allows('/foo.Bar/Method'));
        self::assertTrue($perms->allows('/any.Service/AnyMethod'));
        self::assertTrue($perms->isUnrestricted());
        self::assertSame(['*'], $perms->methods());
    }

    #[Test]
    public function restrictedAllowsOnlyListedMethods(): void
    {
        $perms = ServicePermission::restricted(['/foo.Bar/Method1', '/foo.Bar/Method2']);

        self::assertTrue($perms->allows('/foo.Bar/Method1'));
        self::assertTrue($perms->allows('/foo.Bar/Method2'));
        self::assertFalse($perms->allows('/foo.Bar/Method3'));
        self::assertFalse($perms->isUnrestricted());
        self::assertCount(2, $perms->methods());
    }

    #[Test]
    public function emptyRestrictedDeniesAll(): void
    {
        $perms = ServicePermission::restricted([]);

        self::assertFalse($perms->allows('/foo.Bar/Any'));
        self::assertFalse($perms->isUnrestricted());
        self::assertSame([], $perms->methods());
    }

    #[Test]
    public function constructorFromArray(): void
    {
        $perms = new ServicePermission(['/svc/A']);

        self::assertTrue($perms->allows('/svc/A'));
        self::assertFalse($perms->allows('/svc/B'));
    }
}
