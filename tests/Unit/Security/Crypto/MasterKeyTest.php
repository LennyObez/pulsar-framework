<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use SensitiveParameterValue;
use Throwable;

use function ini_get;
use function ini_set;
use function sprintf;
use function strlen;

#[CoversClass(MasterKey::class)]
final class MasterKeyTest extends TestCase
{
    private string $validHex;
    private string $ignoreArgs;
    private string $paramMaxLen;

    protected function setUp(): void
    {
        // 32-byte key hex-encoded
        $this->validHex = sodium_bin2hex(random_bytes(32));

        // The redaction tests below are only meaningful when the engine records
        // frame arguments and prints them in full: with zend.exception_ignore_args=1
        // no argument reaches a trace, and zend.exception_string_param_max_len
        // defaults to 15, which would truncate a 64-char key and let a leak pass
        // a "does not contain the key" assertion.
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        $this->paramMaxLen = (string) ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '1000');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        ini_set('zend.exception_string_param_max_len', $this->paramMaxLen);
    }

    #[Test]
    public function fromHexCreatesInstance(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        self::assertInstanceOf(MasterKey::class, $masterKey);
    }

    #[Test]
    public function fromHexThrowsForInvalidLength(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('expected');

        $_ = MasterKey::fromHex(sodium_bin2hex(random_bytes(16))); // 16 bytes, need 32
    }

    #[Test]
    public function deriveSubKeyReturnsDeterministicOutput(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $subKey1 = $masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $masterKey->deriveSubKey(1, 'encrypt_');

        self::assertSame($subKey1, $subKey2);
    }

    #[Test]
    public function differentSubKeyIdsDifferentKeys(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $subKey1 = $masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $masterKey->deriveSubKey(2, 'encrypt_');

        self::assertNotSame($subKey1, $subKey2);
    }

    #[Test]
    public function differentContextsDifferentKeys(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $subKey1 = $masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $masterKey->deriveSubKey(1, 'audit___');

        self::assertNotSame($subKey1, $subKey2);
    }

    #[Test]
    public function deriveSubKeyHexReturnsHexString(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $hex = $masterKey->deriveSubKeyHex(1, 'encrypt_');

        // 32-byte key = 64 hex chars
        self::assertSame(64, strlen($hex));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
    }

    #[Test]
    public function shortContextThrows(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('KDF context must be exactly 8 bytes, got 2');

        $masterKey->deriveSubKey(1, 'ab');
    }

    #[Test]
    public function longContextThrows(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('KDF context must be exactly 8 bytes, got 27');

        $masterKey->deriveSubKey(1, 'this_is_a_very_long_context');
    }

    #[Test]
    public function debugInfoRedactsKey(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $debug = $masterKey->__debugInfo();

        self::assertSame('[REDACTED]', $debug['rawKey']);
    }

    #[Test]
    public function fromEnvironmentThrowsWhenMissing(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('PULSAR_MASTER_KEY');

        $_ = MasterKey::fromEnvironment('');
    }

    #[Test]
    public function fromEnvironmentWithExplicitValue(): void
    {
        $masterKey = MasterKey::fromEnvironment($this->validHex);

        self::assertInstanceOf(MasterKey::class, $masterKey);
    }

    #[Test]
    public function hasPreviousKeyReturnsFalseByDefault(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        self::assertFalse($masterKey->hasPreviousKey());
    }

    #[Test]
    public function hasPreviousKeyReturnsTrueWhenPreviousKeyProvided(): void
    {
        $previousHex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($this->validHex, $previousHex);

        self::assertTrue($masterKey->hasPreviousKey());
    }

    #[Test]
    public function derivePreviousSubKeyReturnsNullWithoutPreviousKey(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        self::assertNull($masterKey->derivePreviousSubKey(1, 'encrypt_'));
    }

    #[Test]
    public function derivePreviousSubKeyReturnsDerivedKeyWithPreviousKey(): void
    {
        $previousHex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($this->validHex, $previousHex);

        $previousSubKey = $masterKey->derivePreviousSubKey(1, 'encrypt_');
        self::assertNotNull($previousSubKey);
        self::assertSame(32, strlen($previousSubKey));

        // Verify it matches what we'd get from the previous key directly
        $previousMaster = MasterKey::fromHex($previousHex);
        self::assertSame($previousMaster->deriveSubKey(1, 'encrypt_'), $previousSubKey);
    }

    #[Test]
    public function keyIdReturns16HexChars(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $kid = $masterKey->keyId(2, 'audit___');

        self::assertSame(16, strlen($kid));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $kid);
    }

    #[Test]
    public function keyIdIsDeterministic(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $kid1 = $masterKey->keyId(2, 'audit___');
        $kid2 = $masterKey->keyId(2, 'audit___');

        self::assertSame($kid1, $kid2);
    }

    #[Test]
    public function differentSubKeyIdsProduceDifferentKids(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $kid1 = $masterKey->keyId(1, 'encrypt_');
        $kid2 = $masterKey->keyId(2, 'encrypt_');

        self::assertNotSame($kid1, $kid2);
    }

    #[Test]
    public function previousKeyIdReturnsNullWithoutPreviousKey(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        self::assertNull($masterKey->previousKeyId(2, 'audit___'));
    }

    #[Test]
    public function previousKeyIdReturnsDifferentKidThanCurrent(): void
    {
        $previousHex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($this->validHex, $previousHex);

        $currentKid = $masterKey->keyId(2, 'audit___');
        $previousKid = $masterKey->previousKeyId(2, 'audit___');

        self::assertNotNull($previousKid);
        self::assertNotSame($currentKid, $previousKid);
    }

    #[Test]
    public function serializationThrows(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization');

        serialize($masterKey);
    }

    #[Test]
    public function fromHexWithInvalidPreviousKeyThrows(): void
    {
        $shortPrevious = sodium_bin2hex(random_bytes(16));

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('previous key');

        $_ = MasterKey::fromHex($this->validHex, $shortPrevious);
    }

    #[Test]
    public function debugInfoRedactsPreviousKey(): void
    {
        $previousHex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($this->validHex, $previousHex);

        $debug = $masterKey->__debugInfo();

        self::assertSame('[REDACTED]', $debug['rawKey']);
        self::assertSame('[REDACTED]', $debug['previousRawKey']);
    }

    #[Test]
    public function debugInfoShowsNoneForAbsentPreviousKey(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $debug = $masterKey->__debugInfo();

        self::assertSame('[NONE]', $debug['previousRawKey']);
    }

    #[Test]
    public function unserializationIsForbidden(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization of MasterKey is forbidden');

        $masterKey->__unserialize([]);
    }

    #[Test]
    public function normalizeContextRejectsWrongLength(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('exactly 8 bytes');

        $masterKey->deriveSubKey(1, 'toolong_ctx');
    }

    #[Test]
    public function fromHexKeepsTheKeyOutOfTheExceptionTrace(): void
    {
        // A well-formed key of the wrong length, not 'zz': sodium_hex2bin() marks its
        // own parameter sensitive, so a malformed-hex failure drops the arguments of
        // every frame and would prove nothing. Here MasterKey itself raises, and the
        // fromHex() frame carries whatever the caller passed.
        $wrongLengthHex = sodium_bin2hex(random_bytes(16));

        try {
            $_ = MasterKey::fromHex($wrongLengthHex);
            self::fail('fromHex() accepted a 16-byte key');
        } catch (SecurityException $e) {
            self::assertParameterRedacted($e, 'fromHex', 0, $wrongLengthHex);
        }
    }

    #[Test]
    public function fromHexKeepsTheCurrentKeyOutOfTheTraceWhenThePreviousKeyIsRejected(): void
    {
        // The rotation window is where a live key leaks most cheaply: a misconfigured
        // PULSAR_MASTER_KEY_PREVIOUS raises while the valid current key sits in the
        // same frame.
        $shortPreviousHex = sodium_bin2hex(random_bytes(16));

        try {
            $_ = MasterKey::fromHex($this->validHex, $shortPreviousHex);
            self::fail('fromHex() accepted a 16-byte previous key');
        } catch (SecurityException $e) {
            self::assertParameterRedacted($e, 'fromHex', 0, $this->validHex);
            self::assertParameterRedacted($e, 'fromHex', 1, $shortPreviousHex);
        }
    }

    #[Test]
    public function fromEnvironmentKeepsTheKeyOutOfTheExceptionTrace(): void
    {
        $wrongLengthHex = sodium_bin2hex(random_bytes(16));

        try {
            $_ = MasterKey::fromEnvironment($wrongLengthHex);
            self::fail('fromEnvironment() accepted a 16-byte key');
        } catch (SecurityException $e) {
            self::assertParameterRedacted($e, 'fromEnvironment', 0, $wrongLengthHex);
            self::assertParameterRedacted($e, 'fromHex', 0, $wrongLengthHex);
        }
    }

    /**
     * Assert that argument $position of the $function frame reaches the trace as a
     * redaction placeholder, and that $secret appears in neither the rendered trace
     * nor the rendered exception.
     */
    private static function assertParameterRedacted(
        Throwable $e,
        string $function,
        int $position,
        string $secret,
    ): void {
        $frame = null;

        foreach ($e->getTrace() as $candidate) {
            if (($candidate['function'] ?? null) === $function) {
                $frame = $candidate;

                break;
            }
        }

        self::assertNotNull($frame, sprintf('No %s() frame in the trace', $function));

        $arguments = $frame['args'] ?? [];
        self::assertNotSame([], $arguments, sprintf('%s() frame recorded no arguments', $function));

        self::assertInstanceOf(
            SensitiveParameterValue::class,
            $arguments[$position] ?? null,
            sprintf('%s() argument %d reaches the trace unredacted', $function, $position),
        );

        self::assertStringNotContainsString($secret, $e->getTraceAsString());
        self::assertStringNotContainsString($secret, (string) $e);
    }
}
