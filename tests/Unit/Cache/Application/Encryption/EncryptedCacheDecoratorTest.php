<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Encryption\EncryptedCacheDecorator;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use Pulsar\Security\Crypto\MasterKey;
use ReflectionMethod;

#[CoversClass(EncryptedCacheDecorator::class)]
final class EncryptedCacheDecoratorTest extends TestCase
{
    private ArrayDriver $inner;
    private EncryptedCacheDecorator $decorator;

    protected function setUp(): void
    {
        $this->inner = new ArrayDriver();
        $masterKey = MasterKey::fromHex(str_repeat('ab', 32));

        $this->decorator = new EncryptedCacheDecorator(
            inner: $this->inner,
            masterKey: $masterKey,
            poolName: 'test-pool',
        );
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        $this->decorator->set('key1', 'hello world', null);

        $result = $this->decorator->get('key1');

        self::assertSame('hello world', $result);
    }

    #[Test]
    public function capabilitiesMaskCounterSupportBecauseIncrementThrows(): void
    {
        // The inner array driver advertises atomic increment, but the
        // decorator's increment()/decrement() throw (ciphertext cannot be
        // incremented server-side). Forwarding the inner flags made
        // tags_strategy 'auto' pick StrictTagStrategy on encrypted pools,
        // whose version bumps call increment() — an exception on the first
        // tag invalidation. The decorator must therefore mask everything
        // counter-dependent while passing the rest through.
        self::assertTrue($this->inner->capabilities()->supportsAtomicIncrement);

        $capabilities = $this->decorator->capabilities();

        self::assertFalse($capabilities->supportsAtomicIncrement);
        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertSame($this->inner->capabilities()->supportsBinary, $capabilities->supportsBinary);
        self::assertSame($this->inner->capabilities()->supportsLocksFencing, $capabilities->supportsLocksFencing);
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $result = $this->decorator->get('nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function aadMismatchReturnsNull(): void
    {
        // Encrypt with pool name "test-pool"
        $this->decorator->set('key1', 'secret', null);

        // Create a new decorator with a different pool name
        $masterKey = MasterKey::fromHex(str_repeat('ab', 32));
        $otherDecorator = new EncryptedCacheDecorator(
            inner: $this->inner,
            masterKey: $masterKey,
            poolName: 'different-pool',
        );

        // Reading with a different pool name should fail AAD check
        $result = $otherDecorator->get('key1');

        self::assertNull($result);
    }

    #[Test]
    public function incrementThrowsUnsupportedCapabilityException(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageIsOrContains('increment/decrement');

        $this->decorator->increment('counter');
    }

    #[Test]
    public function decrementThrowsUnsupportedCapabilityException(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageIsOrContains('increment/decrement');

        $this->decorator->decrement('counter');
    }

    #[Test]
    public function hasDelegatesToInnerDriver(): void
    {
        $this->decorator->set('key1', 'value', null);

        self::assertTrue($this->decorator->has('key1'));
        self::assertFalse($this->decorator->has('nonexistent'));
    }

    #[Test]
    public function clearDelegatesToInnerDriver(): void
    {
        $this->decorator->set('key1', 'value', null);

        $result = $this->decorator->clear();

        self::assertTrue($result);
        self::assertNull($this->decorator->get('key1'));
    }

    #[Test]
    public function deleteDelegatesToInnerDriver(): void
    {
        $this->decorator->set('key1', 'value', null);

        $result = $this->decorator->delete('key1');

        self::assertTrue($result);
        self::assertNull($this->decorator->get('key1'));
    }

    #[Test]
    public function nameReturnsPrefixedInnerName(): void
    {
        self::assertSame('encrypted:array', $this->decorator->name());
    }

    #[Test]
    public function decryptionFailureReturnsNullForCorruptCiphertext(): void
    {
        // Store garbage directly in inner driver to simulate corruption
        $this->inner->set('corrupt-key', '{"v":2,"kid":"bad","ct":"corrupt","aad":"badaad"}', null);

        $result = $this->decorator->get('corrupt-key');

        self::assertNull($result);
    }

    #[Test]
    public function getMultipleDelegatesCorrectlyThroughEncryption(): void
    {
        $this->decorator->set('multi-a', 'alpha', null);
        $this->decorator->set('multi-b', 'beta', null);

        $results = $this->decorator->getMultiple(['multi-a', 'multi-b', 'multi-missing']);

        self::assertSame('alpha', $results['multi-a']);
        self::assertSame('beta', $results['multi-b']);
        self::assertNull($results['multi-missing']);
    }

    #[Test]
    public function setMultipleDelegatesCorrectlyThroughEncryption(): void
    {
        $result = $this->decorator->setMultiple([
            'batch-a' => 'alpha',
            'batch-b' => 'beta',
        ], null);

        self::assertTrue($result);
        self::assertSame('alpha', $this->decorator->get('batch-a'));
        self::assertSame('beta', $this->decorator->get('batch-b'));
    }

    #[Test]
    public function deleteMultipleDelegatesCorrectlyThroughEncryption(): void
    {
        $this->decorator->set('del-a', 'alpha', null);
        $this->decorator->set('del-b', 'beta', null);

        $result = $this->decorator->deleteMultiple(['del-a', 'del-b']);

        self::assertTrue($result);
        self::assertNull($this->decorator->get('del-a'));
        self::assertNull($this->decorator->get('del-b'));
    }

    #[Test]
    public function keyRotationReEncryptsValueWithCurrentKey(): void
    {
        // Store a value with the decorator (current key)
        $this->decorator->set('rotation-key', 'secret-data', 3600);

        // Read with the same key to verify it works
        $value = $this->decorator->get('rotation-key');
        self::assertSame('secret-data', $value);

        // The stored ciphertext in the inner driver should be valid
        $raw = $this->inner->get('rotation-key');
        self::assertNotNull($raw);
        self::assertNotSame('secret-data', $raw);
    }

    #[Test]
    public function setStampsPayloadWithAbsoluteExpiry(): void
    {
        // FR-33: set() now stamps the payload with an absolute expiry ('exp') so
        // a re-encrypt on key rotation preserves it. Previously it stored only
        // the raw TTL duration ('ttl'), which the rotation re-encrypt restarted
        // from the read moment, extending the entry's lifetime indefinitely.
        $before = time();
        $this->decorator->set('expiring', 'value', 100);

        $raw = $this->inner->get('expiring');
        self::assertNotNull($raw);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true);
        self::assertArrayHasKey('exp', $decoded);
        self::assertIsInt($decoded['exp']);
        // Absolute timestamp ~ now + 100, not the bare duration 100.
        self::assertGreaterThanOrEqual($before + 100, $decoded['exp']);
        self::assertLessThanOrEqual(time() + 100, $decoded['exp']);
    }

    #[Test]
    public function rotationReEncryptsPreviousKeyEntryAndPreservesExpiry(): void
    {
        // FR-33: an entry written under the previous master key must be readable
        // after rotation (re-encrypted under the current key), and the re-encrypt
        // must keep the original absolute expiry rather than restart the TTL.
        // The AAD MAC also rotates, so without a previous-key fallback the
        // integrity check rejected the entry before re-encryption could run.
        $inner = new ArrayDriver();
        $keyA = str_repeat('ab', 32);
        $keyB = str_repeat('cd', 32);

        // Write under key A with an absolute expiry only seconds away.
        $producer = new EncryptedCacheDecorator(
            inner: $inner,
            masterKey: MasterKey::fromHex($keyA),
            poolName: 'test-pool',
        );
        $encrypt = new ReflectionMethod($producer, 'encryptValue');
        $blob = $encrypt->invoke($producer, 'rk', 'secret', time() + 3);
        self::assertIsString($blob);
        $inner->set('rk', $blob, null);

        // Rotate: key A becomes previous, key B current.
        $rotated = new EncryptedCacheDecorator(
            inner: $inner,
            masterKey: MasterKey::fromHex($keyB, $keyA),
            poolName: 'test-pool',
        );

        // The previous-key entry is decrypted and re-encrypted on read.
        self::assertSame('secret', $rotated->get('rk'));

        // The re-written entry keeps the original absolute expiry, not now+ttl.
        $reEncrypted = $inner->get('rk');
        self::assertNotNull($reEncrypted);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($reEncrypted, true);
        self::assertArrayHasKey('exp', $decoded);
        self::assertIsInt($decoded['exp']);
        self::assertLessThanOrEqual(time() + 5, $decoded['exp'], 'rotation must preserve the original expiry, not extend it');
    }
}
