<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Exception\ApiException;

#[CoversClass(ApiException::class)]
final class ApiExceptionTest extends TestCase
{
    #[Test]
    public function unknownFieldIncludesFieldAndResourceType(): void
    {
        $e = ApiException::unknownField('email', 'users');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('email', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
    }

    #[Test]
    public function fieldLimitExceededIncludesCounts(): void
    {
        $e = ApiException::fieldLimitExceeded(50, 20);

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('50', $e->getMessage());
        self::assertStringContainsString('20', $e->getMessage());
    }

    #[Test]
    public function nestingDepthExceededIncludesDepths(): void
    {
        $e = ApiException::nestingDepthExceeded(5, 3);

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('5', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    #[Test]
    public function includesLimitExceededIncludesCounts(): void
    {
        $e = ApiException::includesLimitExceeded(10, 5);

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('10', $e->getMessage());
        self::assertStringContainsString('5', $e->getMessage());
    }

    #[Test]
    public function entitySerializationBannedReturns500(): void
    {
        $e = ApiException::entitySerializationBanned('App\\Entity\\User', 'corr-123');

        self::assertSame(500, $e->getCode());
        self::assertStringContainsString('App\\Entity\\User', $e->getMessage());
        self::assertStringContainsString('corr-123', $e->getMessage());
        self::assertStringContainsString('ApiResource', $e->getMessage());
    }

    #[Test]
    public function missingResourceAttributeIncludesClass(): void
    {
        $e = ApiException::missingResourceAttribute('App\\Resource\\UserResource');

        self::assertStringContainsString('App\\Resource\\UserResource', $e->getMessage());
        self::assertStringContainsString('#[ApiResource]', $e->getMessage());
    }

    #[Test]
    public function emptyResourceTypeIncludesClass(): void
    {
        $e = ApiException::emptyResourceType('App\\Resource\\EmptyType');

        self::assertStringContainsString('App\\Resource\\EmptyType', $e->getMessage());
    }

    #[Test]
    public function unknownResourceReturns400(): void
    {
        $e = ApiException::unknownResource('widgets');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('widgets', $e->getMessage());
    }

    #[Test]
    public function unknownFilterFieldIncludesContext(): void
    {
        $e = ApiException::unknownFilterField('category', 'products');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('category', $e->getMessage());
        self::assertStringContainsString('products', $e->getMessage());
    }

    #[Test]
    public function unknownFilterOperatorIncludesContext(): void
    {
        $e = ApiException::unknownFilterOperator('like', 'name');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('like', $e->getMessage());
        self::assertStringContainsString('name', $e->getMessage());
    }

    #[Test]
    public function invalidFilterOperatorIncludesFullContext(): void
    {
        $e = ApiException::invalidFilterOperator('gt', 'name', 'users');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('gt', $e->getMessage());
        self::assertStringContainsString('name', $e->getMessage());
        self::assertStringContainsString('users', $e->getMessage());
    }

    #[Test]
    public function invalidFilterValueIncludesContext(): void
    {
        $e = ApiException::invalidFilterValue('abc', 'integer', 'age');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('abc', $e->getMessage());
        self::assertStringContainsString('integer', $e->getMessage());
        self::assertStringContainsString('age', $e->getMessage());
    }

    #[Test]
    public function unauthorizedFilterReturns403(): void
    {
        $e = ApiException::unauthorizedFilter('salary', 'admin');

        self::assertSame(403, $e->getCode());
        self::assertStringContainsString('salary', $e->getMessage());
        self::assertStringContainsString('admin', $e->getMessage());
    }

    #[Test]
    public function unknownSortFieldIncludesContext(): void
    {
        $e = ApiException::unknownSortField('rating', 'products');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('rating', $e->getMessage());
    }

    #[Test]
    public function unauthorizedSortReturns403(): void
    {
        $e = ApiException::unauthorizedSort('internal_score', 'superadmin');

        self::assertSame(403, $e->getCode());
        self::assertStringContainsString('internal_score', $e->getMessage());
        self::assertStringContainsString('superadmin', $e->getMessage());
    }

    #[Test]
    public function unsupportedVersionReturns400(): void
    {
        $e = ApiException::unsupportedVersion('v99');

        self::assertSame(400, $e->getCode());
        self::assertStringContainsString('v99', $e->getMessage());
    }

    #[Test]
    public function notAcceptableReturns406(): void
    {
        $e = ApiException::notAcceptable('text/xml');

        self::assertSame(406, $e->getCode());
        self::assertStringContainsString('text/xml', $e->getMessage());
    }

    /**
     * @param list<mixed> $args
     */
    #[Test]
    #[DataProvider('httpStatusCodeProvider')]
    public function factoryMethodsReturnCorrectStatusCodes(string $method, array $args, int $expectedCode): void
    {
        /** @var ApiException $exception */
        $exception = ApiException::$method(...$args);

        self::assertSame($expectedCode, $exception->getCode());
    }

    /**
     * @return iterable<string, array{string, list<mixed>, int}>
     */
    public static function httpStatusCodeProvider(): iterable
    {
        yield 'unknownField' => ['unknownField', ['f', 'r'], 400];
        yield 'unauthorizedFilter' => ['unauthorizedFilter', ['f', 'r'], 403];
        yield 'unauthorizedSort' => ['unauthorizedSort', ['f', 'r'], 403];
        yield 'notAcceptable' => ['notAcceptable', ['text/xml'], 406];
        yield 'entitySerializationBanned' => ['entitySerializationBanned', ['C', 'id'], 500];
    }
}
