<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestEntry;
use Pulsar\Integrity\ManifestSigner;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(ManifestSigner::class)]
final class ManifestSignerTest extends TestCase
{
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    #[Test]
    public function it_signs_a_manifest_and_returns_hex_string(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();

        $signature = $signer->sign($manifest);

        self::assertNotEmpty($signature);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $signature);
    }

    #[Test]
    public function it_produces_deterministic_signatures(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();

        $sig1 = $signer->sign($manifest);
        $sig2 = $signer->sign($manifest);

        self::assertSame($sig1, $sig2);
    }

    #[Test]
    public function it_verifies_a_valid_signature(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();

        $signature = $signer->sign($manifest);

        $signedManifest = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
        );

        self::assertTrue($signer->verify($signedManifest));
    }

    #[Test]
    public function it_rejects_manifest_with_no_signature(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();

        // Manifest has signature=null by default
        self::assertFalse($signer->verify($manifest));
    }

    #[Test]
    public function it_rejects_manifest_with_wrong_signature(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();

        $tamperedManifest = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: 'deadbeef' . str_repeat('00', 28),
        );

        self::assertFalse($signer->verify($tamperedManifest));
    }

    #[Test]
    public function it_rejects_signature_when_manifest_content_changes(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();

        $signature = $signer->sign($manifest);

        // Create a modified manifest with the original signature
        $modifiedManifest = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: [
                new ManifestEntry(path: 'src/Kernel.php', hash: 'tampered_hash', size: 999),
            ],
            signature: $signature,
        );

        self::assertFalse($signer->verify($modifiedManifest));
    }

    #[Test]
    public function it_rejects_when_version_changes(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();
        $signature = $signer->sign($manifest);

        $modified = new IntegrityManifest(
            version: 999,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
        );

        self::assertFalse($signer->verify($modified));
    }

    #[Test]
    public function it_rejects_when_generated_at_changes(): void
    {
        $signer = new ManifestSigner($this->masterKey);
        $manifest = $this->createTestManifest();
        $signature = $signer->sign($manifest);

        $modified = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt + 1,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
        );

        self::assertFalse($signer->verify($modified));
    }

    #[Test]
    public function different_master_keys_produce_different_signatures(): void
    {
        $key1 = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $key2 = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        $signer1 = new ManifestSigner($key1);
        $signer2 = new ManifestSigner($key2);

        $manifest = $this->createTestManifest();

        $sig1 = $signer1->sign($manifest);
        $sig2 = $signer2->sign($manifest);

        self::assertNotSame($sig1, $sig2);
    }

    #[Test]
    public function it_cannot_verify_signature_from_different_key(): void
    {
        $key1 = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $key2 = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        $signer1 = new ManifestSigner($key1);
        $signer2 = new ManifestSigner($key2);

        $manifest = $this->createTestManifest();
        $signature = $signer1->sign($manifest);

        $signedManifest = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
        );

        self::assertFalse($signer2->verify($signedManifest));
    }

    #[Test]
    public function it_signs_manifest_with_empty_entries(): void
    {
        $signer = new ManifestSigner($this->masterKey);

        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );

        $signature = $signer->sign($manifest);
        self::assertNotEmpty($signature);

        $signed = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            signature: $signature,
        );

        self::assertTrue($signer->verify($signed));
    }

    #[Test]
    public function it_signs_manifest_with_multiple_entries(): void
    {
        $signer = new ManifestSigner($this->masterKey);

        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 3,
            entries: [
                new ManifestEntry(path: 'a.php', hash: 'aaa', size: 10),
                new ManifestEntry(path: 'b.php', hash: 'bbb', size: 20),
                new ManifestEntry(path: 'c.php', hash: 'ccc', size: 30),
            ],
        );

        $signature = $signer->sign($manifest);

        $signed = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
        );

        self::assertTrue($signer->verify($signed));
    }

    #[Test]
    public function signature_does_not_include_signature_field(): void
    {
        $signer = new ManifestSigner($this->masterKey);

        // Two identical manifests, one with a signature field and one without,
        // should produce the same HMAC (signature field is excluded from canonicalization)
        $unsigned = $this->createTestManifest();
        $sig = $signer->sign($unsigned);

        $withSig = new IntegrityManifest(
            version: $unsigned->version,
            algorithm: $unsigned->algorithm,
            generatedAt: $unsigned->generatedAt,
            frameworkVersion: $unsigned->frameworkVersion,
            entryCount: $unsigned->entryCount,
            entries: $unsigned->entries,
            signature: 'some_existing_sig',
        );

        $sigFromSigned = $signer->sign($withSig);

        self::assertSame($sig, $sigFromSigned);
    }

    private function createTestManifest(): IntegrityManifest
    {
        return new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Kernel.php', hash: 'abc123def456', size: 1024),
            ],
        );
    }
}
