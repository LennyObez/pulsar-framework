<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Security\Crypto\MasterKey;

use function strlen;

#[CoversClass(CmsKeyManager::class)]
final class CmsKeyManagerTest extends TestCase
{
    private CmsKeyManager $keyManager;

    protected function setUp(): void
    {
        // Generate a valid 32-byte (64 hex char) master key for testing
        $hex = bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex);
        $this->keyManager = new CmsKeyManager($masterKey);
    }

    #[Test]
    public function previewKeyReturnsBinaryString(): void
    {
        $key = $this->keyManager->previewKey();

        self::assertNotEmpty($key);
        // Default KDF output is SODIUM_CRYPTO_SECRETBOX_KEYBYTES = 32 bytes
        self::assertSame(32, strlen($key));
    }

    #[Test]
    public function mediaSigningKeyReturnsBinaryString(): void
    {
        $key = $this->keyManager->mediaSigningKey();

        self::assertNotEmpty($key);
        self::assertSame(32, strlen($key));
    }

    #[Test]
    public function exportKeyReturnsBinaryString(): void
    {
        $key = $this->keyManager->exportKey();

        self::assertNotEmpty($key);
        self::assertSame(32, strlen($key));
    }

    #[Test]
    public function downloadKeyReturnsBinaryString(): void
    {
        $key = $this->keyManager->downloadKey();

        self::assertNotEmpty($key);
        self::assertSame(32, strlen($key));
    }

    #[Test]
    public function evidenceKeyReturnsBinaryString(): void
    {
        $key = $this->keyManager->evidenceKey();

        self::assertNotEmpty($key);
        self::assertSame(32, strlen($key));
    }

    #[Test]
    public function allKeysAreDifferent(): void
    {
        $preview = $this->keyManager->previewKey();
        $media = $this->keyManager->mediaSigningKey();
        $export = $this->keyManager->exportKey();
        $download = $this->keyManager->downloadKey();
        $evidence = $this->keyManager->evidenceKey();

        // Each derived subkey should be unique due to different subkey IDs and contexts
        $keys = [$preview, $media, $export, $download, $evidence];
        $unique = array_unique($keys);
        self::assertCount(5, $unique);
    }

    #[Test]
    public function keysAreDeterministic(): void
    {
        $key1 = $this->keyManager->previewKey();
        $key2 = $this->keyManager->previewKey();

        self::assertSame($key1, $key2);
    }

    #[Test]
    public function differentMasterKeysProduceDifferentSubkeys(): void
    {
        $hex2 = bin2hex(random_bytes(32));
        $masterKey2 = MasterKey::fromHex($hex2);
        $km2 = new CmsKeyManager($masterKey2);

        self::assertNotSame($this->keyManager->previewKey(), $km2->previewKey());
    }
}
