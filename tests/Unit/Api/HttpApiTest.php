<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Validator;
use Pulsar\Http\Validation\Violation;

#[CoversClass(Api::class)]
final class HttpApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function requestIsPublicApi(): void
    {
        self::assertHasApiAttribute(Request::class);
        self::assertClassIsReadonly(Request::class);
    }

    #[Test]
    public function responseIsPublicApi(): void
    {
        self::assertHasApiAttribute(Response::class);
        self::assertClassIsReadonly(Response::class);
    }

    #[Test]
    public function responseHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(Response::class, 'text');
        self::assertStaticFactoryExists(Response::class, 'html');
        self::assertStaticFactoryExists(Response::class, 'json');
    }

    #[Test]
    public function headerBagIsPublicApi(): void
    {
        self::assertHasApiAttribute(HeaderBag::class);
        self::assertClassIsReadonly(HeaderBag::class);
    }

    #[Test]
    public function methodEnumIsPublicApi(): void
    {
        self::assertHasApiAttribute(Method::class);
        self::assertEnumCases(Method::class, ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH']);
    }

    #[Test]
    public function responseStatusEnumIsPublicApi(): void
    {
        self::assertHasApiAttribute(ResponseStatus::class);
    }

    #[Test]
    public function middlewareInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(MiddlewareInterface::class);
    }

    #[Test]
    public function ruleInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(RuleInterface::class);
    }

    #[Test]
    public function validationResultIsPublicApi(): void
    {
        self::assertHasApiAttribute(ValidationResult::class);
        self::assertClassIsReadonly(ValidationResult::class);
    }

    #[Test]
    public function violationIsPublicApi(): void
    {
        self::assertHasApiAttribute(Violation::class);
        self::assertClassIsReadonly(Violation::class);
    }

    #[Test]
    public function validationExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(ValidationException::class);
    }

    #[Test]
    public function validatorIsPublicApi(): void
    {
        self::assertHasApiAttribute(Validator::class);
    }

    #[Test]
    public function responseEmitterIsPublicApi(): void
    {
        self::assertHasApiAttribute(ResponseEmitter::class);
    }

    #[Test]
    public function rateLimitResultIsPublicApi(): void
    {
        self::assertHasApiAttribute(RateLimitResult::class);
        self::assertClassIsReadonly(RateLimitResult::class);
    }
}
