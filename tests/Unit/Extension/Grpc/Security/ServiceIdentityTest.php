<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Security\ServiceIdentity;
use Pulsar\Extension\Grpc\Security\ServicePermission;

#[CoversClass(ServiceIdentity::class)]
final class ServiceIdentityTest extends TestCase
{
    #[Test]
    public function constructionCompilesPermissions(): void
    {
        $identity = new ServiceIdentity(
            name: 'payment-service',
            trustLevel: 'internal',
            allowedMethods: ['/billing.Payment/Charge', '/billing.Payment/Refund'],
        );

        self::assertSame('payment-service', $identity->name);
        self::assertSame('internal', $identity->trustLevel);
        self::assertInstanceOf(ServicePermission::class, $identity->permissions);
    }

    #[Test]
    public function isMethodAllowedDelegatesToPermissions(): void
    {
        $identity = new ServiceIdentity(
            name: 'svc',
            trustLevel: 'internal',
            allowedMethods: ['/foo.Bar/Allowed'],
        );

        self::assertTrue($identity->isMethodAllowed('/foo.Bar/Allowed'));
        self::assertFalse($identity->isMethodAllowed('/foo.Bar/Denied'));
    }

    #[Test]
    public function wildcardAllowsAllMethods(): void
    {
        $identity = new ServiceIdentity(
            name: 'admin',
            trustLevel: 'internal',
            allowedMethods: ['*'],
        );

        self::assertTrue($identity->isMethodAllowed('/any.Service/Any'));
    }
}
