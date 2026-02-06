<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheIntegrity;

use function strlen;

#[CoversClass(CacheIntegrity::class)]
final class CacheTamperTest extends TestCase
{
    private string $hmacKey;
    private CacheIntegrity $integrity;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->integrity = new CacheIntegrity($this->hmacKey);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cache_tamper_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function tamperedPayloadDetectedAfterWrite(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered_payload.cache.bin';
        $data = serialize(['secret' => 'value', 'count' => 42]);
        $this->integrity->writeEnvelope($path, $data, false);

        // Read the raw envelope, tamper with the payload bytes, and rewrite
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        // Modify bytes in the middle of the file to tamper with payload content
        $tampered = $raw;
        $len = strlen($tampered);
        for ($i = (int) ($len * 0.3); $i < (int) ($len * 0.5) && $i < $len; $i++) {
            $tampered[$i] = $tampered[$i] === "\x00" ? "\x01" : "\x00";
        }
        file_put_contents($path, $tampered);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function tamperedHmacDetected(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered_hmac.cache.bin';
        $data = serialize(['important' => 'data']);
        $this->integrity->writeEnvelope($path, $data, false);

        // Read raw envelope, decode, tamper with hmac, re-encode
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $envelope = unserialize($raw, ['allowed_classes' => false]);
        self::assertIsArray($envelope);

        // Tamper with the HMAC
        $originalHmac = $envelope['hmac'];
        self::assertIsString($originalHmac);
        $envelope['hmac'] = str_repeat('f', strlen($originalHmac));

        file_put_contents($path, serialize($envelope));

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function missingHmacFieldRejected(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'missing_hmac.cache.bin';

        // Write a hand-crafted envelope with missing hmac field
        $envelope = serialize([
            'schema' => 1,
            'payload' => serialize(['data' => true]),
            'sha256' => hash('sha256', serialize(['data' => true])),
            // No 'hmac' field
            'encrypted' => false,
        ]);
        file_put_contents($path, $envelope);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function corruptedSerializedDataRejected(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'corrupted_serialized.cache.bin';

        // Write completely invalid serialized data
        file_put_contents($path, 'a:0:{this is not valid serialized data}}}');

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function dataOnlyFormatVerifiesBinaryNotPhp(): void
    {
        // Verify that the cache files use .cache.bin format (binary serialized data)
        // not .php format (which would be executable)
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'data.cache.bin';
        $data = serialize(['key' => 'value']);
        $this->integrity->writeEnvelope($path, $data, false);

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        // The file should NOT start with <?php
        self::assertStringNotContainsString('<?php', $raw);

        // The file should contain serialized PHP data (starts with 'a:' for serialized array)
        self::assertStringStartsWith('a:', $raw);

        // Verify it round-trips correctly
        $result = $this->integrity->readEnvelope($path, []);
        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function outerEnvelopeUsesAllowedClassesFalse(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'outer_envelope.cache.bin';
        $data = serialize(['safe' => 'data']);
        $this->integrity->writeEnvelope($path, $data, false);

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        // Verify the outer envelope is a plain array (no object instantiation)
        // When using allowed_classes=false, any serialized objects become __PHP_Incomplete_Class
        $envelope = unserialize($raw, ['allowed_classes' => false]);
        self::assertIsArray($envelope);

        // All keys should be plain scalars, no object instances
        self::assertIsInt($envelope['schema']);
        self::assertIsString($envelope['payload']);
        self::assertIsString($envelope['sha256']);
        self::assertIsString($envelope['hmac']);
        self::assertIsBool($envelope['encrypted']);
    }

    #[Test]
    public function tamperedPayloadWithValidSchemaStillRejected(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'schema_valid_payload_tampered.cache.bin';
        $originalData = serialize(['original' => true]);
        $this->integrity->writeEnvelope($path, $originalData, false);

        // Read the envelope, change the payload but keep schema=1
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $envelope = unserialize($raw, ['allowed_classes' => false]);
        self::assertIsArray($envelope);

        // Replace payload with different data
        $envelope['payload'] = serialize(['hacked' => true]);
        // Keep original sha256 and hmac (they won't match the new payload)

        file_put_contents($path, serialize($envelope));

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function recomputedSha256ButWrongHmacRejected(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'recomputed_sha256.cache.bin';
        $originalData = serialize(['original' => true]);
        $this->integrity->writeEnvelope($path, $originalData, false);

        // Read the envelope, change payload and recompute sha256 but not hmac
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $envelope = unserialize($raw, ['allowed_classes' => false]);
        self::assertIsArray($envelope);

        $newPayload = serialize(['attacker' => 'injected']);
        $envelope['payload'] = $newPayload;
        $envelope['sha256'] = hash('sha256', $newPayload);
        // hmac is still from the original payload

        file_put_contents($path, serialize($envelope));

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function emptyPayloadHandledCorrectly(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'empty_payload.cache.bin';
        $emptyData = serialize([]);
        $this->integrity->writeEnvelope($path, $emptyData, false);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertSame([], $result);
    }

    #[Test]
    public function nonArrayEnvelopeRejected(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'non_array.cache.bin';

        // Write a serialized string instead of an array
        file_put_contents($path, serialize('not an array'));

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
