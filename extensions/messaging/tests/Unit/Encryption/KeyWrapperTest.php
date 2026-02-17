<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\KeyWrapper;

use function strlen;

use const SODIUM_CRYPTO_PWHASH_SALTBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

#[CoversClass(KeyWrapper::class)]
final class KeyWrapperTest extends TestCase
{
    private KeyWrapper $wrapper;

    protected function setUp(): void
    {
        $this->wrapper = new KeyWrapper();
    }

    public function testWrapUnwrapRoundTrip(): void
    {
        $privateKey = random_bytes(32);
        $password = 'correct-horse-battery-staple';

        $wrapped = $this->wrapper->wrap($privateKey, $password);

        self::assertArrayHasKey('wrappedKey', $wrapped);
        self::assertArrayHasKey('nonce', $wrapped);
        self::assertArrayHasKey('salt', $wrapped);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($wrapped['nonce']));
        self::assertSame(SODIUM_CRYPTO_PWHASH_SALTBYTES, strlen($wrapped['salt']));

        $unwrapped = $this->wrapper->unwrap(
            $wrapped['wrappedKey'],
            $wrapped['nonce'],
            $wrapped['salt'],
            $password,
        );

        self::assertSame($privateKey, $unwrapped);
    }

    public function testUnwrapWithWrongPasswordReturnsFalse(): void
    {
        $privateKey = random_bytes(32);

        $wrapped = $this->wrapper->wrap($privateKey, 'right-password');
        $result = $this->wrapper->unwrap(
            $wrapped['wrappedKey'],
            $wrapped['nonce'],
            $wrapped['salt'],
            'wrong-password',
        );

        self::assertFalse($result);
    }

    public function testRewrapWithNewPassword(): void
    {
        $privateKey = random_bytes(32);
        $oldPassword = 'old-password';
        $newPassword = 'new-password';

        $wrapped = $this->wrapper->wrap($privateKey, $oldPassword);
        $rewrapped = $this->wrapper->rewrap(
            $wrapped['wrappedKey'],
            $wrapped['nonce'],
            $wrapped['salt'],
            $oldPassword,
            $newPassword,
        );

        self::assertIsArray($rewrapped);

        // Old password no longer works
        $oldResult = $this->wrapper->unwrap(
            $rewrapped['wrappedKey'],
            $rewrapped['nonce'],
            $rewrapped['salt'],
            $oldPassword,
        );
        self::assertFalse($oldResult);

        // New password works
        $newResult = $this->wrapper->unwrap(
            $rewrapped['wrappedKey'],
            $rewrapped['nonce'],
            $rewrapped['salt'],
            $newPassword,
        );
        self::assertSame($privateKey, $newResult);
    }

    public function testRewrapWithWrongOldPasswordReturnsFalse(): void
    {
        $wrapped = $this->wrapper->wrap(random_bytes(32), 'correct');
        $result = $this->wrapper->rewrap(
            $wrapped['wrappedKey'],
            $wrapped['nonce'],
            $wrapped['salt'],
            'wrong',
            'new',
        );

        self::assertFalse($result);
    }
}
