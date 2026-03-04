<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\InMemoryTokenStore;

#[CoversClass(InMemoryTokenStore::class)]
final class InMemoryTokenStoreTest extends TestCase
{
    private InMemoryTokenStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryTokenStore();
    }

    #[Test]
    public function storeAndRetrieve(): void
    {
        $this->store->store('tok_123', 'encrypted_value', 'pan');

        self::assertSame('encrypted_value', $this->store->retrieve('tok_123'));
    }

    #[Test]
    public function retrieveReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->store->retrieve('tok_nonexistent'));
    }

    #[Test]
    public function existsReturnsTrueForStoredToken(): void
    {
        $this->store->store('tok_abc', 'data', 'ctx');

        self::assertTrue($this->store->exists('tok_abc'));
    }

    #[Test]
    public function existsReturnsFalseForUnknownToken(): void
    {
        self::assertFalse($this->store->exists('tok_unknown'));
    }

    #[Test]
    public function removeDeletesToken(): void
    {
        $this->store->store('tok_del', 'data', 'ctx');
        self::assertTrue($this->store->exists('tok_del'));

        $this->store->remove('tok_del');

        self::assertFalse($this->store->exists('tok_del'));
        self::assertNull($this->store->retrieve('tok_del'));
    }

    #[Test]
    public function removeNonexistentTokenIsNoOp(): void
    {
        // Should not throw
        $this->store->remove('tok_never_existed');

        self::assertFalse($this->store->exists('tok_never_existed'));
    }

    #[Test]
    public function multipleTokensAreIndependent(): void
    {
        $this->store->store('tok_1', 'enc_a', 'pan');
        $this->store->store('tok_2', 'enc_b', 'ssn');

        self::assertSame('enc_a', $this->store->retrieve('tok_1'));
        self::assertSame('enc_b', $this->store->retrieve('tok_2'));

        $this->store->remove('tok_1');

        self::assertNull($this->store->retrieve('tok_1'));
        self::assertSame('enc_b', $this->store->retrieve('tok_2'));
    }

    #[Test]
    public function storeOverwritesExistingToken(): void
    {
        $this->store->store('tok_ow', 'original', 'ctx');
        $this->store->store('tok_ow', 'updated', 'ctx');

        self::assertSame('updated', $this->store->retrieve('tok_ow'));
    }
}
