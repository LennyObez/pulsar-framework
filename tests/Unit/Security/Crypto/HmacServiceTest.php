<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\HmacService;

use function strlen;

#[CoversClass(HmacService::class)]
final class HmacServiceTest extends TestCase
{
    private HmacService $service;
    private string $key;

    protected function setUp(): void
    {
        $this->service = new HmacService();
        // SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN = 16 bytes minimum
        $this->key = str_repeat('k', 32);
    }

    #[Test]
    public function computeHexReturnsHexString(): void
    {
        $hex = $this->service->computeHex('hello world', $this->key);

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
        // BLAKE2b-256 outputs 32 bytes = 64 hex chars
        self::assertSame(64, strlen($hex));
    }

    #[Test]
    public function computeHexIsDeterministic(): void
    {
        $hex1 = $this->service->computeHex('same message', $this->key);
        $hex2 = $this->service->computeHex('same message', $this->key);

        self::assertSame($hex1, $hex2);
    }

    #[Test]
    public function computeHexDiffersForDifferentMessages(): void
    {
        $hex1 = $this->service->computeHex('message A', $this->key);
        $hex2 = $this->service->computeHex('message B', $this->key);

        self::assertNotSame($hex1, $hex2);
    }

    #[Test]
    public function verifyHexReturnsTrueForValidMac(): void
    {
        $hex = $this->service->computeHex('test data', $this->key);

        self::assertTrue($this->service->verifyHex('test data', $hex, $this->key));
    }

    #[Test]
    public function verifyHexReturnsFalseForTamperedMessage(): void
    {
        $hex = $this->service->computeHex('original', $this->key);

        self::assertFalse($this->service->verifyHex('tampered', $hex, $this->key));
    }

    #[Test]
    public function verifyHexReturnsFalseForWrongMac(): void
    {
        $wrongMac = str_repeat('a', 64);

        self::assertFalse($this->service->verifyHex('any message', $wrongMac, $this->key));
    }
}
