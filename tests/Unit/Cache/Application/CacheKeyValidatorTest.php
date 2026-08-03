<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\CacheKeyValidator;

use function strlen;

#[CoversClass(CacheKeyValidator::class)]
final class CacheKeyValidatorTest extends TestCase
{
    #[Test]
    public function validKeyPasses(): void
    {
        CacheKeyValidator::validate('valid-key_123.test');

        // Validates without throwing — assert a stable property of the input
        self::assertSame(18, strlen('valid-key_123.test'));
    }

    #[Test]
    public function emptyStringThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not be empty');

        CacheKeyValidator::validate('');
    }

    #[Test]
    public function reservedCharCurlyOpenThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key{bad');
    }

    #[Test]
    public function reservedCharCurlyCloseThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key}bad');
    }

    #[Test]
    public function reservedCharParenOpenThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key(bad');
    }

    #[Test]
    public function reservedCharParenCloseThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key)bad');
    }

    #[Test]
    public function reservedCharForwardSlashThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key/bad');
    }

    #[Test]
    public function reservedCharBackslashThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key\\bad');
    }

    #[Test]
    public function reservedCharAtThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key@bad');
    }

    #[Test]
    public function reservedCharColonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved characters');

        CacheKeyValidator::validate('key:bad');
    }

    #[Test]
    public function overLengthKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not exceed 250 characters');

        CacheKeyValidator::validate(str_repeat('a', 251));
    }

    #[Test]
    public function validateMultipleWithNonStringKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be a string');

        CacheKeyValidator::validateMultiple([123]);
    }

    #[Test]
    public function validateMultipleAllValidKeysPasses(): void
    {
        $keys = ['key1', 'key2', 'key3'];
        CacheKeyValidator::validateMultiple($keys);

        // All three keys passed validation — count confirms input was processed
        self::assertCount(3, $keys);
    }

    #[Test]
    public function keyOfExactly250CharactersIsAccepted(): void
    {
        $key = str_repeat('a', 250);
        CacheKeyValidator::validate($key);

        // Exactly at the boundary — must succeed
        self::assertSame(250, strlen($key));
    }
}
