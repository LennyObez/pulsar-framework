<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestEntry;
use Pulsar\Integrity\ManifestScope;
use Pulsar\Integrity\ManifestSigner;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(ManifestSigner::class)]
final class ManifestSignerTest extends TestCase
{
    private HmacInterface $hmac;
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->hmac = new HmacService();
        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    #[Test]
    public function it_signs_a_manifest_and_returns_hex_string(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
        $manifest = $this->createTestManifest();

        $signature = $signer->sign($manifest);

        self::assertNotEmpty($signature);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $signature);
    }

    #[Test]
    public function it_produces_deterministic_signatures(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
        $manifest = $this->createTestManifest();

        $sig1 = $signer->sign($manifest);
        $sig2 = $signer->sign($manifest);

        self::assertSame($sig1, $sig2);
    }

    #[Test]
    public function it_verifies_a_valid_signature(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
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
            scope: $manifest->scope,
        );

        self::assertTrue($signer->verify($signedManifest));
    }

    #[Test]
    public function it_rejects_manifest_with_no_signature(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
        $manifest = $this->createTestManifest();

        // Manifest has signature=null by default
        self::assertFalse($signer->verify($manifest));
    }

    #[Test]
    public function it_rejects_manifest_with_wrong_signature(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
        $manifest = $this->createTestManifest();

        $tamperedManifest = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: 'deadbeef' . str_repeat('00', 28),
            scope: $manifest->scope,
        );

        self::assertFalse($signer->verify($tamperedManifest));
    }

    #[Test]
    public function it_rejects_signature_when_manifest_content_changes(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
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
            scope: $manifest->scope,
        );

        self::assertFalse($signer->verify($modifiedManifest));
    }

    #[Test]
    public function it_rejects_when_version_changes(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
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
            scope: $manifest->scope,
        );

        self::assertFalse($signer->verify($modified));
    }

    #[Test]
    public function it_rejects_when_generated_at_changes(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
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
            scope: $manifest->scope,
        );

        self::assertFalse($signer->verify($modified));
    }

    #[Test]
    public function different_master_keys_produce_different_signatures(): void
    {
        $key1 = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $key2 = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        $signer1 = new ManifestSigner($this->hmac, $key1);
        $signer2 = new ManifestSigner($this->hmac, $key2);

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

        $signer1 = new ManifestSigner($this->hmac, $key1);
        $signer2 = new ManifestSigner($this->hmac, $key2);

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
            scope: $manifest->scope,
        );

        self::assertFalse($signer2->verify($signedManifest));
    }

    /**
     * The scope decides what the verifier looks at, so it is worth as much to an
     * attacker as the hashes are. Adding one exclude pattern would otherwise
     * carve a directory out of the check without disturbing the signature.
     */
    #[Test]
    public function widening_the_exclusions_invalidates_the_signature(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);

        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [new ManifestEntry(path: 'src/Kernel.php', hash: 'aaa', size: 10)],
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        $signature = $signer->sign($manifest);

        $honest = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
            scope: $manifest->scope,
        );

        self::assertTrue($signer->verify($honest));

        $carvedOut = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
            scope: new ManifestScope(['src/**/*.php'], ['src/Uploads/**']),
        );

        self::assertFalse($signer->verify($carvedOut));
    }

    #[Test]
    public function narrowing_the_inclusions_invalidates_the_signature(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);

        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            scope: new ManifestScope(['src/**/*.php', 'config/**/*.php'], []),
        );

        $signature = $signer->sign($manifest);

        $narrowed = new IntegrityManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            generatedAt: $manifest->generatedAt,
            frameworkVersion: $manifest->frameworkVersion,
            entryCount: $manifest->entryCount,
            entries: $manifest->entries,
            signature: $signature,
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        self::assertFalse($signer->verify($narrowed));
    }

    #[Test]
    public function it_signs_manifest_with_empty_entries(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);

        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        $signature = $signer->sign($manifest);
        self::assertNotEmpty($signature);

        $signed = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
            signature: $signature,
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        self::assertTrue($signer->verify($signed));
    }

    #[Test]
    public function it_signs_manifest_with_multiple_entries(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);

        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 3,
            entries: [
                new ManifestEntry(path: 'a.php', hash: 'aaa', size: 10),
                new ManifestEntry(path: 'b.php', hash: 'bbb', size: 20),
                new ManifestEntry(path: 'c.php', hash: 'ccc', size: 30),
            ],
            scope: new ManifestScope(['src/**/*.php'], []),
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
            scope: $manifest->scope,
        );

        self::assertTrue($signer->verify($signed));
    }

    #[Test]
    public function signature_does_not_include_signature_field(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);

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
            scope: $unsigned->scope,
        );

        $sigFromSigned = $signer->sign($withSig);

        self::assertSame($sig, $sigFromSigned);
    }

    /**
     * A signature over a scopeless manifest would attest to a document that
     * cannot say what it covers, so the signer produces none.
     */
    #[Test]
    public function it_refuses_to_sign_a_manifest_without_a_scope(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessageIsOrContains('declares no scope');

        $_ = $signer->sign($this->createScopelessManifest());
    }

    #[Test]
    public function it_does_not_authenticate_a_manifest_without_a_scope(): void
    {
        $signer = new ManifestSigner($this->hmac, $this->masterKey);
        $signature = $signer->sign($this->createTestManifest());

        $scopeless = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Kernel.php', hash: 'abc123def456', size: 1024),
            ],
            signature: $signature,
        );

        self::assertFalse($signer->verify($scopeless));
    }

    private function createScopelessManifest(): IntegrityManifest
    {
        return new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 0,
            entries: [],
        );
    }

    private function createTestManifest(): IntegrityManifest
    {
        return new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0-rc.2',
            entryCount: 1,
            entries: [
                new ManifestEntry(path: 'src/Kernel.php', hash: 'abc123def456', size: 1024),
            ],
            scope: new ManifestScope(['src/**/*.php'], []),
        );
    }
}
