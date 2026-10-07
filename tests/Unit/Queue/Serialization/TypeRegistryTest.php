<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Serialization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Serialization\TypeRegistry;

#[CoversClass(TypeRegistry::class)]
final class TypeRegistryTest extends TestCase
{
    #[Test]
    public function isAllowedReturnsFalseForUnregisteredClass(): void
    {
        $registry = new TypeRegistry();

        self::assertFalse($registry->isAllowed('App\\Jobs\\Unknown'));
    }

    #[Test]
    public function registerMakesClassAllowed(): void
    {
        $registry = new TypeRegistry();
        $registry->register('App\\Jobs\\SendEmail');

        self::assertTrue($registry->isAllowed('App\\Jobs\\SendEmail'));
    }

    #[Test]
    public function assertAllowedPassesForRegisteredClass(): void
    {
        $this->expectNotToPerformAssertions();

        $registry = new TypeRegistry();
        $registry->register('App\\Jobs\\Valid');

        $registry->assertAllowed('App\\Jobs\\Valid');
    }

    #[Test]
    public function assertAllowedThrowsForUnregisteredClass(): void
    {
        $registry = new TypeRegistry();

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('not registered in the type allowlist');

        $registry->assertAllowed('App\\Jobs\\Malicious');
    }

    #[Test]
    public function multipleRegistrations(): void
    {
        $registry = new TypeRegistry();
        $registry->register('App\\Jobs\\A');
        $registry->register('App\\Jobs\\B');

        self::assertTrue($registry->isAllowed('App\\Jobs\\A'));
        self::assertTrue($registry->isAllowed('App\\Jobs\\B'));
        self::assertFalse($registry->isAllowed('App\\Jobs\\C'));
    }
}
