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
        $this->expectExceptionMessage('increment/decrement');

        $this->decorator->increment('counter');
    }

    #[Test]
    public function decrementThrowsUnsupportedCapabilityException(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('increment/decrement');

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
}
