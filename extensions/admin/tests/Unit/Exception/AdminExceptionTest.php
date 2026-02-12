<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;

final class AdminExceptionTest extends TestCase
{
    #[Test]
    public function admin_exception_disabled(): void
    {
        $e = AdminException::disabled();

        self::assertStringContainsString('disabled', $e->getMessage());
        self::assertStringContainsString('ADMIN_ENABLED', $e->getMessage());
    }

    #[Test]
    public function admin_exception_invalid_configuration(): void
    {
        $e = AdminException::invalidConfiguration('missing route prefix');

        self::assertStringContainsString('missing route prefix', $e->getMessage());
    }

    #[Test]
    public function admin_exception_resource_already_registered(): void
    {
        $e = AdminException::resourceAlreadyRegistered('users');

        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('already registered', $e->getMessage());
    }

    #[Test]
    public function access_denied_insufficient_role(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('admin');

        self::assertInstanceOf(AdminException::class, $e);
        self::assertStringContainsString('admin', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function access_denied_operation_denied(): void
    {
        $e = AdminAccessDeniedException::operationDenied('users', ResourceOperation::Delete);

        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('delete', $e->getMessage());
    }

    #[Test]
    public function access_denied_two_factor_required(): void
    {
        $e = AdminAccessDeniedException::twoFactorRequired();

        self::assertStringContainsString('two-factor', $e->getMessage());
    }

    #[Test]
    public function resource_not_found_resource(): void
    {
        $e = ResourceNotFoundException::resource('widgets');

        self::assertInstanceOf(AdminException::class, $e);
        self::assertStringContainsString('widgets', $e->getMessage());
    }

    #[Test]
    public function resource_not_found_record(): void
    {
        $e = ResourceNotFoundException::record('users', 'user-123');

        self::assertStringContainsString('user-123', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
    }

    #[Test]
    public function resource_validation_exception_from_violations(): void
    {
        $violations = [
            ['field' => 'name', 'message' => 'Name is required', 'rule' => 'required'],
            ['field' => 'email', 'message' => 'Invalid email', 'rule' => 'email'],
        ];

        $e = ResourceValidationException::fromViolations($violations);

        self::assertInstanceOf(AdminException::class, $e);
        self::assertStringContainsString('2 error(s)', $e->getMessage());
        self::assertCount(2, $e->violations);
        self::assertSame('name', $e->violations[0]['field']);
        self::assertSame('email', $e->violations[1]['field']);
    }

    #[Test]
    public function resource_validation_exception_single_violation(): void
    {
        $violations = [
            ['field' => 'title', 'message' => 'Title required', 'rule' => 'required'],
        ];

        $e = ResourceValidationException::fromViolations($violations);

        self::assertStringContainsString('1 error(s)', $e->getMessage());
        self::assertCount(1, $e->violations);
    }

    #[Test]
    public function resource_validation_exception_empty_violations(): void
    {
        $e = ResourceValidationException::fromViolations([]);

        self::assertStringContainsString('0 error(s)', $e->getMessage());
        self::assertSame([], $e->violations);
    }
}
