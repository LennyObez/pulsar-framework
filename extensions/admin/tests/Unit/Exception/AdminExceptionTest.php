<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;

#[CoversClass(AdminException::class)]
#[CoversClass(AdminAccessDeniedException::class)]
#[CoversClass(ResourceNotFoundException::class)]
#[CoversClass(ResourceValidationException::class)]
final class AdminExceptionTest extends TestCase
{
    #[Test]
    public function disabledProducesCorrectMessage(): void
    {
        $e = AdminException::disabled();

        self::assertStringContainsString('disabled', $e->getMessage());
        self::assertStringContainsString('ADMIN_ENABLED', $e->getMessage());
    }

    #[Test]
    public function invalidConfigurationIncludesDetail(): void
    {
        $e = AdminException::invalidConfiguration('missing route_prefix');

        self::assertStringContainsString('missing route_prefix', $e->getMessage());
        self::assertStringContainsString('Invalid admin configuration', $e->getMessage());
    }

    #[Test]
    public function resourceAlreadyRegisteredIncludesName(): void
    {
        $e = AdminException::resourceAlreadyRegistered('users');

        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('already registered', $e->getMessage());
    }

    #[Test]
    public function insufficientRoleIncludesRole(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('admin');

        self::assertStringContainsString('admin', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function operationDeniedIncludesResourceAndOperation(): void
    {
        $e = AdminAccessDeniedException::operationDenied('users', ResourceOperation::Delete);

        self::assertStringContainsString('delete', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function twoFactorRequiredMessage(): void
    {
        $e = AdminAccessDeniedException::twoFactorRequired();

        self::assertStringContainsString('two-factor', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    #[Test]
    public function adminAccessDeniedExtendsAdminException(): void
    {
        $e = AdminAccessDeniedException::insufficientRole('admin');

        self::assertInstanceOf(AdminException::class, $e);
    }

    #[Test]
    public function resourceNotFoundResource(): void
    {
        $e = ResourceNotFoundException::resource('widgets');

        self::assertStringContainsString('widgets', $e->getMessage());
    }

    #[Test]
    public function resourceNotFoundRecord(): void
    {
        $e = ResourceNotFoundException::record('users', 'user-123');

        self::assertStringContainsString('user-123', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
    }

    #[Test]
    public function resourceValidationExceptionFromViolations(): void
    {
        $violations = [
            ['field' => 'name', 'message' => 'Name is required', 'rule' => 'required'],
            ['field' => 'email', 'message' => 'Invalid email', 'rule' => 'email'],
        ];

        $e = ResourceValidationException::fromViolations($violations);

        self::assertStringContainsString('2 error(s)', $e->getMessage());
        self::assertCount(2, $e->violations);
        self::assertSame('name', $e->violations[0]['field']);
        self::assertSame('email', $e->violations[1]['field']);
    }

    #[Test]
    public function resourceValidationExceptionSingleViolation(): void
    {
        $violations = [
            ['field' => 'title', 'message' => 'Title required', 'rule' => 'required'],
        ];

        $e = ResourceValidationException::fromViolations($violations);

        self::assertStringContainsString('1 error(s)', $e->getMessage());
        self::assertCount(1, $e->violations);
    }

    #[Test]
    public function resourceValidationExceptionEmptyViolations(): void
    {
        $e = ResourceValidationException::fromViolations([]);

        self::assertStringContainsString('0 error(s)', $e->getMessage());
        self::assertSame([], $e->violations);
    }
}
