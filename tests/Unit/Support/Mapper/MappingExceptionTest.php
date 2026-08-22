<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Mapper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Mapper\MappingException;
use RuntimeException;
use ValueError;

#[CoversClass(MappingException::class)]
final class MappingExceptionTest extends TestCase
{
    #[Test]
    public function classNotFoundIncludesClassNameAndPrevious(): void
    {
        $previous = new RuntimeException('autoload failed');
        $ex = MappingException::classNotFound('App\\Missing', $previous);

        self::assertStringContainsString('App\\Missing', $ex->getMessage());
        self::assertSame($previous, $ex->getPrevious());
    }

    #[Test]
    public function noConstructorIncludesClassName(): void
    {
        $ex = MappingException::noConstructor('App\\NoCtorClass');

        self::assertStringContainsString('App\\NoCtorClass', $ex->getMessage());
        self::assertStringContainsString('no constructor', $ex->getMessage());
    }

    #[Test]
    public function missingRequiredIncludesClassAndParam(): void
    {
        $ex = MappingException::missingRequired('App\\User', 'email');

        self::assertStringContainsString('App\\User', $ex->getMessage());
        self::assertStringContainsString('email', $ex->getMessage());
    }

    #[Test]
    public function nullNotAllowedIncludesClassAndParam(): void
    {
        $ex = MappingException::nullNotAllowed('App\\Dto', 'name');

        self::assertStringContainsString('App\\Dto', $ex->getMessage());
        self::assertStringContainsString('name', $ex->getMessage());
        self::assertStringContainsString('null', $ex->getMessage());
    }

    #[Test]
    public function typeMismatchIncludesExpectedAndActualType(): void
    {
        $ex = MappingException::typeMismatch('App\\Config', 'port', 'int', 'hello');

        self::assertStringContainsString('App\\Config', $ex->getMessage());
        self::assertStringContainsString('port', $ex->getMessage());
        self::assertStringContainsString('int', $ex->getMessage());
        self::assertStringContainsString('string', $ex->getMessage());
    }

    #[Test]
    public function enumFailedIncludesEnumClassAndValue(): void
    {
        $ex = MappingException::enumFailed('App\\Dto', 'status', 'App\\Status', 'invalid');

        self::assertStringContainsString('App\\Status', $ex->getMessage());
        self::assertStringContainsString('invalid', $ex->getMessage());
        self::assertStringContainsString('status', $ex->getMessage());
    }

    #[Test]
    public function enumFailedWithPrevious(): void
    {
        $prev = new ValueError('not a valid backing value');
        $ex = MappingException::enumFailed('A\\B', 'x', 'A\\E', 'bad', $prev);

        self::assertSame($prev, $ex->getPrevious());
    }

    #[Test]
    public function factoryFailedIncludesClassAndCause(): void
    {
        $prev = new RuntimeException('fromArray boom');
        $ex = MappingException::factoryFailed('App\\Widget', $prev);

        self::assertStringContainsString('App\\Widget', $ex->getMessage());
        self::assertStringContainsString('fromArray boom', $ex->getMessage());
        self::assertSame($prev, $ex->getPrevious());
    }

    #[Test]
    public function constructionFailedIncludesClassAndCause(): void
    {
        $prev = new RuntimeException('ctor failed');
        $ex = MappingException::constructionFailed('App\\Service', $prev);

        self::assertStringContainsString('App\\Service', $ex->getMessage());
        self::assertStringContainsString('ctor failed', $ex->getMessage());
    }

    #[Test]
    public function invalidListItemIncludesIndexAndClass(): void
    {
        $ex = MappingException::invalidListItem('App\\Item', 3);

        self::assertStringContainsString('3', $ex->getMessage());
        self::assertStringContainsString('App\\Item', $ex->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $ex = MappingException::noConstructor('X');

        self::assertInstanceOf(RuntimeException::class, $ex);
    }
}
