<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\Binding\ModelBindingException;

#[CoversClass(ModelBindingException::class)]
final class ModelBindingExceptionTest extends TestCase
{
    #[Test]
    public function modelNotFoundReturns404(): void
    {
        $e = ModelBindingException::modelNotFound('App\\Models\\User', 'id', '42');

        self::assertSame(404, $e->getCode());
        self::assertStringContainsString('App\\Models\\User', $e->getMessage());
        self::assertStringContainsString('id', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
    }

    #[Test]
    public function authorizationFailedReturns403(): void
    {
        $e = ModelBindingException::authorizationFailed('App\\Models\\User', '42');

        self::assertSame(403, $e->getCode());
        self::assertStringContainsString('App\\Models\\User', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
    }

    #[Test]
    public function missingPolicyReturns500(): void
    {
        $e = ModelBindingException::missingPolicy('App\\Models\\User');

        self::assertSame(500, $e->getCode());
        self::assertStringContainsString('App\\Models\\User', $e->getMessage());
        self::assertStringContainsString('authorization policy', $e->getMessage());
    }

    #[Test]
    public function missingResolverReturns500(): void
    {
        $e = ModelBindingException::missingResolver('App\\Models\\User');

        self::assertSame(500, $e->getCode());
        self::assertStringContainsString('App\\Models\\User', $e->getMessage());
        self::assertStringContainsString('resolver', $e->getMessage());
    }

    #[Test]
    public function invalidKeyTypeReturns404(): void
    {
        $e = ModelBindingException::invalidKeyType('user', 'int', 'abc');

        self::assertSame(404, $e->getCode());
        self::assertStringContainsString('user', $e->getMessage());
        self::assertStringContainsString('int', $e->getMessage());
        self::assertStringContainsString('abc', $e->getMessage());
    }

    #[Test]
    public function invalidKeyNameReturns400(): void
    {
        $e = ModelBindingException::invalidKeyName('email', 'App\\Models\\User');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('email', $e->getMessage());
        self::assertStringContainsString('App\\Models\\User', $e->getMessage());
    }

    #[Test]
    public function authBypassForbiddenReturns403(): void
    {
        $e = ModelBindingException::authBypassForbidden('users.show');

        self::assertSame(403, $e->getCode());
        self::assertStringContainsString('users.show', $e->getMessage());
        self::assertStringContainsString('PublicRoute', $e->getMessage());
    }
}
