<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;

#[CoversClass(ResourceNotFoundException::class)]
#[CoversClass(ResourceValidationException::class)]
final class ResourceExceptionTest extends TestCase
{
    #[Test]
    public function resourceNotFoundIncludesName(): void
    {
        $e = ResourceNotFoundException::resource('products');

        self::assertStringContainsString('products', $e->getMessage());
        self::assertStringContainsString('not found', $e->getMessage());
        self::assertInstanceOf(AdminException::class, $e);
    }

    #[Test]
    public function recordNotFoundIncludesResourceAndId(): void
    {
        $e = ResourceNotFoundException::record('users', '42');

        self::assertStringContainsString('42', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
        self::assertStringContainsString('not found', $e->getMessage());
    }

    #[Test]
    public function validationExceptionFromViolations(): void
    {
        $violations = [
            ['field' => 'email', 'message' => 'Invalid email', 'rule' => 'email'],
            ['field' => 'name', 'message' => 'Required', 'rule' => 'required'],
        ];

        $e = ResourceValidationException::fromViolations($violations);

        self::assertStringContainsString('2 error(s)', $e->getMessage());
        self::assertCount(2, $e->violations);
        self::assertSame('email', $e->violations[0]['field']);
        self::assertSame('Required', $e->violations[1]['message']);
        self::assertInstanceOf(AdminException::class, $e);
    }

    #[Test]
    public function validationExceptionWithSingleViolation(): void
    {
        $violations = [
            ['field' => 'title', 'message' => 'Too long', 'rule' => 'max_length'],
        ];

        $e = ResourceValidationException::fromViolations($violations);

        self::assertStringContainsString('1 error(s)', $e->getMessage());
        self::assertCount(1, $e->violations);
    }

    #[Test]
    public function validationExceptionWithEmptyViolations(): void
    {
        $e = ResourceValidationException::fromViolations([]);

        self::assertStringContainsString('0 error(s)', $e->getMessage());
        self::assertSame([], $e->violations);
    }
}
