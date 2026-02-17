<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;

final class ResourceValidationExceptionTest extends TestCase
{
    #[Test]
    public function from_violations_single(): void
    {
        $violations = [
            ['field' => 'email', 'message' => 'Email is required', 'rule' => 'required'],
        ];

        $exception = ResourceValidationException::fromViolations($violations);

        self::assertSame('Validation failed with 1 error(s)', $exception->getMessage());
        self::assertCount(1, $exception->violations);
        self::assertSame('email', $exception->violations[0]['field']);
        self::assertSame('Email is required', $exception->violations[0]['message']);
        self::assertSame('required', $exception->violations[0]['rule']);
    }

    #[Test]
    public function from_violations_multiple(): void
    {
        $violations = [
            ['field' => 'name', 'message' => 'Name is required', 'rule' => 'required'],
            ['field' => 'email', 'message' => 'Invalid email', 'rule' => 'email'],
            ['field' => 'age', 'message' => 'Must be positive', 'rule' => 'min'],
        ];

        $exception = ResourceValidationException::fromViolations($violations);

        self::assertSame('Validation failed with 3 error(s)', $exception->getMessage());
        self::assertCount(3, $exception->violations);
    }

    #[Test]
    public function resource_validation_exception_extends_admin_exception(): void
    {
        $exception = ResourceValidationException::fromViolations([]);

        self::assertInstanceOf(AdminException::class, $exception);
    }

    // --- ResourceNotFoundException ---

    #[Test]
    public function resource_not_found_resource(): void
    {
        $exception = ResourceNotFoundException::resource('products');

        self::assertSame('Resource "products" not found', $exception->getMessage());
        self::assertInstanceOf(AdminException::class, $exception);
    }

    #[Test]
    public function resource_not_found_record(): void
    {
        $exception = ResourceNotFoundException::record('users', '42');

        self::assertSame('Record "42" not found in resource "users"', $exception->getMessage());
    }

    // --- AdminException ---

    #[Test]
    public function admin_exception_disabled(): void
    {
        $exception = AdminException::disabled();

        self::assertStringContainsString('Admin panel is disabled', $exception->getMessage());
    }

    #[Test]
    public function admin_exception_invalid_configuration(): void
    {
        $exception = AdminException::invalidConfiguration('missing required key');

        self::assertStringContainsString('Invalid admin configuration', $exception->getMessage());
        self::assertStringContainsString('missing required key', $exception->getMessage());
    }

    #[Test]
    public function admin_exception_resource_already_registered(): void
    {
        $exception = AdminException::resourceAlreadyRegistered('users');

        self::assertSame('Resource "users" is already registered', $exception->getMessage());
    }
}
