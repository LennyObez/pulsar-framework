<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;

#[CoversClass(ResourceNotFoundException::class)]
final class ResourceNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function extendsAdminException(): void
    {
        $exception = ResourceNotFoundException::resource('users');

        self::assertInstanceOf(AdminException::class, $exception);
    }

    #[Test]
    public function resourceIncludesName(): void
    {
        $exception = ResourceNotFoundException::resource('orders');

        self::assertStringContainsString('orders', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function recordIncludesResourceAndId(): void
    {
        $exception = ResourceNotFoundException::record('users', '42');

        self::assertStringContainsString('users', $exception->getMessage());
        self::assertStringContainsString('42', $exception->getMessage());
    }

    #[Test]
    public function recordWithUuidId(): void
    {
        $exception = ResourceNotFoundException::record('invoices', 'a1b2c3d4-e5f6-7890-abcd-ef1234567890');

        self::assertStringContainsString('invoices', $exception->getMessage());
        self::assertStringContainsString('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $exception->getMessage());
    }
}
