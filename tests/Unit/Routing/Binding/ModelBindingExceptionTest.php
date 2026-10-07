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

    /**
     * The refusal a policy hook produces is answered with the status a missing
     * row produces, and the two must not drift apart: a `403` here beside a
     * `404` there is an existence oracle for every caller the policy refuses.
     * The diagnosis stays in the message, which reaches the log and never the
     * client.
     */
    #[Test]
    public function authorizationFailedIsIndistinguishableFromAMissingRow(): void
    {
        $e = ModelBindingException::authorizationFailed('App\\Models\\User', '42');

        self::assertSame(
            ModelBindingException::modelNotFound('App\\Models\\User', 'id', '42')->getCode(),
            $e->getCode(),
        );
        self::assertSame(404, $e->getCode());
        self::assertStringContainsString('App\\Models\\User', $e->getMessage());
        self::assertStringContainsString('42', $e->getMessage());
    }

    #[Test]
    public function undeclaredAuthorizationOptOutReturns500(): void
    {
        $e = ModelBindingException::undeclaredAuthorizationOptOut('users.show');

        self::assertSame(500, $e->getCode());
        self::assertStringContainsString('users.show', $e->getMessage());
        self::assertStringContainsString('_without_authorization', $e->getMessage());
    }

    #[Test]
    public function authorizationMandatoryReturns500(): void
    {
        $e = ModelBindingException::authorizationMandatory('banking', 'users.show');

        self::assertSame(500, $e->getCode());
        self::assertStringContainsString('banking', $e->getMessage());
        self::assertStringContainsString('users.show', $e->getMessage());
    }

    #[Test]
    public function authorizationForAnotherRouteReturns500(): void
    {
        $e = ModelBindingException::authorizationForAnotherRoute('users.show');

        self::assertSame(500, $e->getCode());
        self::assertStringContainsString('users.show', $e->getMessage());
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
