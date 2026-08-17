<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use ValueError;

#[CoversNothing]
final class MethodTest extends TestCase
{
    #[Test]
    #[DataProvider('safeMethodProvider')]
    public function isSafeReturnsTrueForSafeMethods(Method $method): void
    {
        self::assertTrue($method->isSafe());
    }

    /** @return iterable<string, array{Method}> */
    public static function safeMethodProvider(): iterable
    {
        yield 'GET' => [Method::GET];
        yield 'HEAD' => [Method::HEAD];
        yield 'OPTIONS' => [Method::OPTIONS];
        yield 'TRACE' => [Method::TRACE];
    }

    #[Test]
    #[DataProvider('unsafeMethodProvider')]
    public function isSafeReturnsFalseForUnsafeMethods(Method $method): void
    {
        self::assertFalse($method->isSafe());
    }

    /** @return iterable<string, array{Method}> */
    public static function unsafeMethodProvider(): iterable
    {
        yield 'POST' => [Method::POST];
        yield 'PUT' => [Method::PUT];
        yield 'DELETE' => [Method::DELETE];
        yield 'PATCH' => [Method::PATCH];
        yield 'CONNECT' => [Method::CONNECT];
    }

    #[Test]
    #[DataProvider('idempotentMethodProvider')]
    public function isIdempotentReturnsTrueForIdempotentMethods(Method $method): void
    {
        self::assertTrue($method->isIdempotent());
    }

    /** @return iterable<string, array{Method}> */
    public static function idempotentMethodProvider(): iterable
    {
        yield 'GET' => [Method::GET];
        yield 'HEAD' => [Method::HEAD];
        yield 'PUT' => [Method::PUT];
        yield 'DELETE' => [Method::DELETE];
        yield 'OPTIONS' => [Method::OPTIONS];
        yield 'TRACE' => [Method::TRACE];
    }

    #[Test]
    #[DataProvider('nonIdempotentMethodProvider')]
    public function isIdempotentReturnsFalseForNonIdempotentMethods(Method $method): void
    {
        self::assertFalse($method->isIdempotent());
    }

    /** @return iterable<string, array{Method}> */
    public static function nonIdempotentMethodProvider(): iterable
    {
        yield 'POST' => [Method::POST];
        yield 'PATCH' => [Method::PATCH];
        yield 'CONNECT' => [Method::CONNECT];
    }

    #[Test]
    #[DataProvider('bodyMethodProvider')]
    public function mayHaveBodyReturnsTrueForBodyMethods(Method $method): void
    {
        self::assertTrue($method->mayHaveBody());
    }

    /** @return iterable<string, array{Method}> */
    public static function bodyMethodProvider(): iterable
    {
        yield 'POST' => [Method::POST];
        yield 'PUT' => [Method::PUT];
        yield 'PATCH' => [Method::PATCH];
    }

    #[Test]
    #[DataProvider('noBodyMethodProvider')]
    public function mayHaveBodyReturnsFalseForNoBodyMethods(Method $method): void
    {
        self::assertFalse($method->mayHaveBody());
    }

    /** @return iterable<string, array{Method}> */
    public static function noBodyMethodProvider(): iterable
    {
        yield 'GET' => [Method::GET];
        yield 'HEAD' => [Method::HEAD];
        yield 'DELETE' => [Method::DELETE];
        yield 'CONNECT' => [Method::CONNECT];
        yield 'OPTIONS' => [Method::OPTIONS];
        yield 'TRACE' => [Method::TRACE];
    }

    #[Test]
    public function fromStringParsesCaseInsensitive(): void
    {
        self::assertSame(Method::GET, Method::fromString('get'));
        self::assertSame(Method::POST, Method::fromString('post'));
        self::assertSame(Method::DELETE, Method::fromString('Delete'));
        self::assertSame(Method::PATCH, Method::fromString('PATCH'));
        self::assertSame(Method::CONNECT, Method::fromString('connect'));
        self::assertSame(Method::TRACE, Method::fromString('trace'));
        self::assertSame(Method::HEAD, Method::fromString('head'));
        self::assertSame(Method::OPTIONS, Method::fromString('options'));
        self::assertSame(Method::PUT, Method::fromString('put'));
    }

    #[Test]
    public function fromStringThrowsForInvalidMethod(): void
    {
        $this->expectException(ValueError::class);

        (void) Method::fromString('INVALID');
    }

    #[Test]
    public function valuePropertyReturnsUppercaseString(): void
    {
        self::assertSame('GET', Method::GET->value);
        self::assertSame('POST', Method::POST->value);
        self::assertSame('CONNECT', Method::CONNECT->value);
        self::assertSame('TRACE', Method::TRACE->value);
    }
}
