<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\CompositeKeyProvider;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;

use function random_bytes;
use function sodium_bin2hex;
use function strlen;

#[CoversClass(CompositeKeyProvider::class)]
final class CompositeKeyProviderTest extends TestCase
{
    private string $masterHex;
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->masterHex = sodium_bin2hex(random_bytes(32));
        $this->masterKey = MasterKey::fromHex($this->masterHex);
    }

    #[Test]
    public function fallbackToMasterKeyWhenNoOverrideExists(): void
    {
        $provider = new CompositeKeyProvider($this->masterKey);

        $derived = $provider->deriveSubKey(1, 'encrypt_');
        $expected = $this->masterKey->deriveSubKey(1, 'encrypt_');

        self::assertSame($expected, $derived);
    }

    #[Test]
    public function overrideTakesPrecedenceOverKdfDerivation(): void
    {
        $overrideKey = random_bytes(32);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $overrideKey,
        ]);

        $result = $provider->deriveSubKey(1, 'encrypt_');

        self::assertSame($overrideKey, $result);
        // Must differ from KDF-derived key
        self::assertNotSame($this->masterKey->deriveSubKey(1, 'encrypt_'), $result);
    }

    #[Test]
    public function nonOverriddenContextDelegatesToMasterKey(): void
    {
        $overrideKey = random_bytes(32);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $overrideKey,
        ]);

        // audit___ is not overridden, should delegate to MasterKey
        $derived = $provider->deriveSubKey(2, 'audit___');
        $expected = $this->masterKey->deriveSubKey(2, 'audit___');

        self::assertSame($expected, $derived);
    }

    #[Test]
    public function invalidOverrideKeyLengthThrowsOnDerive(): void
    {
        // Override is 16 bytes but deriveSubKey defaults to 32 bytes
        $shortKey = random_bytes(16);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $shortKey,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be exactly 32 bytes, got 16');

        $provider->deriveSubKey(1, 'encrypt_');
    }

    #[Test]
    public function overrideKeyMatchingRequestedLengthSucceeds(): void
    {
        // Request a 16-byte subkey with a 16-byte override
        $key16 = random_bytes(16);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $key16,
        ]);

        $result = $provider->deriveSubKey(1, 'encrypt_', 16);

        self::assertSame($key16, $result);
        self::assertSame(16, strlen($result));
    }

    #[Test]
    public function deriveSubKeyHexReturnsHexEncodedResult(): void
    {
        $provider = new CompositeKeyProvider($this->masterKey);

        $hex = $provider->deriveSubKeyHex(1, 'encrypt_');

        self::assertSame(64, strlen($hex));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
    }

    #[Test]
    public function deriveSubKeyHexWithOverrideReturnsOverrideAsHex(): void
    {
        $overrideKey = random_bytes(32);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $overrideKey,
        ]);

        $hex = $provider->deriveSubKeyHex(1, 'encrypt_');

        self::assertSame(sodium_bin2hex($overrideKey), $hex);
    }

    #[Test]
    public function emptyOverrideKeyThrowsOnConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not be empty');

        new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => '',
        ]);
    }

    #[Test]
    public function emptyContextThrowsOnConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('context must not be empty');

        new CompositeKeyProvider($this->masterKey, [
            '' => random_bytes(32),
        ]);
    }

    #[Test]
    public function serializationThrows(): void
    {
        $provider = new CompositeKeyProvider($this->masterKey);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization');

        serialize($provider);
    }

    #[Test]
    public function debugInfoRedactsKeyMaterial(): void
    {
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => random_bytes(32),
        ]);

        $debug = $provider->__debugInfo();

        self::assertSame('[REDACTED]', $debug['primary']);
        self::assertStringContainsString('1 override(s)', $debug['overrides']);
        self::assertStringContainsString('encrypt_', $debug['overrides']);
    }

    #[Test]
    public function multipleOverridesWorkIndependently(): void
    {
        $encKey = random_bytes(32);
        $auditKey = random_bytes(32);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $encKey,
            'audit___' => $auditKey,
        ]);

        self::assertSame($encKey, $provider->deriveSubKey(1, 'encrypt_'));
        self::assertSame($auditKey, $provider->deriveSubKey(2, 'audit___'));
    }

    #[Test]
    public function noOverridesIsIdenticalToMasterKey(): void
    {
        $provider = new CompositeKeyProvider($this->masterKey);

        // Test multiple contexts
        $contexts = ['encrypt_', 'audit___', 'stud_enc', 'stud_mac'];
        foreach ($contexts as $i => $context) {
            self::assertSame(
                $this->masterKey->deriveSubKey($i + 1, $context),
                $provider->deriveSubKey($i + 1, $context),
                "Context {$context} should match MasterKey derivation",
            );
        }
    }

    #[Test]
    public function customLengthWithOverrideMustMatchExactly(): void
    {
        // 32-byte override but requesting 24 bytes
        $key32 = random_bytes(32);
        $provider = new CompositeKeyProvider($this->masterKey, [
            'encrypt_' => $key32,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be exactly 24 bytes, got 32');

        $provider->deriveSubKey(1, 'encrypt_', 24);
    }

    #[Test]
    public function unserializationIsForbidden(): void
    {
        $provider = new CompositeKeyProvider($this->masterKey);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization of CompositeKeyProvider is forbidden');

        $provider->__unserialize([]);
    }
}
