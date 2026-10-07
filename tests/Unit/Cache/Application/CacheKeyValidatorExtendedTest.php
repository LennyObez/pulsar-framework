<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\CacheKeyValidator;

#[CoversClass(CacheKeyValidator::class)]
final class CacheKeyValidatorExtendedTest extends TestCase
{
    #[Test]
    public function validateAcceptsValidKey(): void
    {
        $this->expectNotToPerformAssertions();

        CacheKeyValidator::validate('valid-key_name.123');
    }

    #[Test]
    public function validateRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not be empty');

        CacheKeyValidator::validate('');
    }

    #[Test]
    public function validateRejectsKeyExceeding250Characters(): void
    {
        $longKey = str_repeat('a', 251);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not exceed');

        CacheKeyValidator::validate($longKey);
    }

    #[Test]
    public function validateAcceptsKeyOfExactly250Characters(): void
    {
        $this->expectNotToPerformAssertions();

        $key = str_repeat('a', 250);

        CacheKeyValidator::validate($key);
    }

    #[Test]
    #[DataProvider('reservedCharacters')]
    public function validateRejectsReservedCharacters(string $char): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key' . $char . 'part');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedCharacters(): iterable
    {
        yield 'open brace' => ['{'];
        yield 'close brace' => ['}'];
        yield 'open paren' => ['('];
        yield 'close paren' => [')'];
        yield 'forward slash' => ['/'];
        yield 'backslash' => ['\\'];
        yield 'at sign' => ['@'];
        yield 'colon' => [':'];
    }

    #[Test]
    public function validateMultipleAcceptsValidKeys(): void
    {
        $this->expectNotToPerformAssertions();

        CacheKeyValidator::validateMultiple(['key-a', 'key-b', 'key-c']);
    }

    #[Test]
    public function validateMultipleRejectsNonStringKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be a string');

        CacheKeyValidator::validateMultiple(['valid', 42]);
    }

    #[Test]
    public function validateMultipleRejectsInvalidKeyInBatch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CacheKeyValidator::validateMultiple(['valid', '']);
    }
}
