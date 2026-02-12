<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Themes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Internal\Themes\ThemeProvenanceVerifier;

use function base64_encode;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(ThemeProvenanceVerifier::class)]
final class ThemeProvenanceVerifierTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_prov_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // -- Unsigned archives ----------------------------------------------------

    #[Test]
    public function unsignedArchiveReturnsUnsignedResult(): void
    {
        $archivePath = $this->createTempFile('archive content');
        $config = new ThemesConfig(trustedPublicKeys: []);
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath);

        self::assertTrue($result->hashValid);
        self::assertFalse($result->signaturePresent);
        self::assertFalse($result->signatureValid);
    }

    #[Test]
    public function unsignedArchiveAcceptableWhenNotRequired(): void
    {
        $archivePath = $this->createTempFile('archive content');
        $config = new ThemesConfig(requireSignedThemes: false);
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath);

        self::assertTrue($result->isAcceptable(requireSigned: false));
    }

    #[Test]
    public function unsignedArchiveNotAcceptableWhenRequired(): void
    {
        $archivePath = $this->createTempFile('archive content');
        $config = new ThemesConfig(requireSignedThemes: true);
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath);

        self::assertFalse($result->isAcceptable(requireSigned: true));
    }

    // -- Valid Ed25519 signature -----------------------------------------------

    #[Test]
    public function validEd25519SignatureVerified(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $content = 'valid archive content';
        $archivePath = $this->createTempFile($content);
        $signature = sodium_crypto_sign_detached($content, $secretKey);
        $sigPath = $this->createTempFile(base64_encode($signature), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [base64_encode($publicKey)],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertTrue($result->hashValid);
        self::assertTrue($result->signaturePresent);
        self::assertTrue($result->signatureValid);
        self::assertNull($result->error);
    }

    // -- Invalid Ed25519 signature --------------------------------------------

    #[Test]
    public function invalidEd25519SignatureDetected(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $archivePath = $this->createTempFile('archive content');
        // Create a random invalid 64-byte signature
        $sigPath = $this->createTempFile(base64_encode(random_bytes(64)), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [base64_encode($publicKey)],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertTrue($result->hashValid);
        self::assertTrue($result->signaturePresent);
        self::assertFalse($result->signatureValid);
        self::assertNotNull($result->error);
    }

    // -- Tampered archive -----------------------------------------------------

    #[Test]
    public function tamperedArchiveSignatureFails(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $originalContent = 'original archive content';
        $signature = sodium_crypto_sign_detached($originalContent, $secretKey);
        $sigPath = $this->createTempFile(base64_encode($signature), 'sig');

        // Write tampered content instead of original
        $archivePath = $this->createTempFile('tampered archive content');

        $config = new ThemesConfig(
            trustedPublicKeys: [base64_encode($publicKey)],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertFalse($result->signatureValid);
    }

    // -- Multiple trusted keys ------------------------------------------------

    #[Test]
    public function multipleTrustedKeysFirstMatches(): void
    {
        $keypair1 = sodium_crypto_sign_keypair();
        $secretKey1 = sodium_crypto_sign_secretkey($keypair1);
        $publicKey1 = sodium_crypto_sign_publickey($keypair1);

        $keypair2 = sodium_crypto_sign_keypair();
        $publicKey2 = sodium_crypto_sign_publickey($keypair2);

        $content = 'signed with key 1';
        $archivePath = $this->createTempFile($content);
        $signature = sodium_crypto_sign_detached($content, $secretKey1);
        $sigPath = $this->createTempFile(base64_encode($signature), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [
                base64_encode($publicKey1),
                base64_encode($publicKey2),
            ],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertTrue($result->signatureValid);
    }

    #[Test]
    public function multipleTrustedKeysSecondMatches(): void
    {
        $keypair1 = sodium_crypto_sign_keypair();
        $publicKey1 = sodium_crypto_sign_publickey($keypair1);

        $keypair2 = sodium_crypto_sign_keypair();
        $secretKey2 = sodium_crypto_sign_secretkey($keypair2);
        $publicKey2 = sodium_crypto_sign_publickey($keypair2);

        $content = 'signed with key 2';
        $archivePath = $this->createTempFile($content);
        $signature = sodium_crypto_sign_detached($content, $secretKey2);
        $sigPath = $this->createTempFile(base64_encode($signature), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [
                base64_encode($publicKey1),
                base64_encode($publicKey2),
            ],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertTrue($result->signatureValid);
    }

    #[Test]
    public function noTrustedKeysMatch(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $untrustedKeypair = sodium_crypto_sign_keypair();
        $untrustedPublicKey = sodium_crypto_sign_publickey($untrustedKeypair);

        $content = 'signed with unknown key';
        $archivePath = $this->createTempFile($content);
        $signature = sodium_crypto_sign_detached($content, $secretKey);
        $sigPath = $this->createTempFile(base64_encode($signature), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [base64_encode($untrustedPublicKey)],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertFalse($result->signatureValid);
    }

    // -- Non-existent archive -------------------------------------------------

    #[Test]
    public function nonexistentArchiveReturnsFailed(): void
    {
        $config = new ThemesConfig();
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($this->tmpDir . '/nonexistent.zip');

        self::assertFalse($result->hashValid);
        self::assertNotNull($result->error);
    }

    // -- Invalid signature format ---------------------------------------------

    #[Test]
    public function invalidSignatureFormatReturnsFailed(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $archivePath = $this->createTempFile('archive content');
        // Invalid base64 that decodes to wrong length
        $sigPath = $this->createTempFile(base64_encode('too short'), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [base64_encode($publicKey)],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        self::assertFalse($result->hashValid);
        self::assertNotNull($result->error);
        self::assertStringContainsString('signature format', $result->error);
    }

    // -- Malformed public key skipped -----------------------------------------

    #[Test]
    public function malformedPublicKeySkippedGracefully(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $content = 'content signed with valid key';
        $archivePath = $this->createTempFile($content);
        $signature = sodium_crypto_sign_detached($content, $secretKey);
        $sigPath = $this->createTempFile(base64_encode($signature), 'sig');

        $config = new ThemesConfig(
            trustedPublicKeys: [
                base64_encode('malformed-key-too-short'),
                base64_encode($publicKey),
            ],
        );
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath, $sigPath);

        // Should skip malformed key and succeed with the valid one
        self::assertTrue($result->signatureValid);
    }

    // -- Helpers --------------------------------------------------------------

    private function createTempFile(string $content, string $extension = 'dat'): string
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
        file_put_contents($path, $content);

        return $path;
    }
}
