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
    public function test_unsigned_archive_returns_unsigned_result(): void
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
    public function test_unsigned_archive_acceptable_when_not_required(): void
    {
        $archivePath = $this->createTempFile('archive content');
        $config = new ThemesConfig(requireSignedThemes: false);
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath);

        self::assertTrue($result->isAcceptable(requireSigned: false));
    }

    #[Test]
    public function test_unsigned_archive_not_acceptable_when_required(): void
    {
        $archivePath = $this->createTempFile('archive content');
        $config = new ThemesConfig(requireSignedThemes: true);
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($archivePath);

        self::assertFalse($result->isAcceptable(requireSigned: true));
    }

    // -- Valid Ed25519 signature -----------------------------------------------

    #[Test]
    public function test_valid_ed25519_signature_verified(): void
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
    public function test_invalid_ed25519_signature_detected(): void
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
    public function test_tampered_archive_signature_fails(): void
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
    public function test_multiple_trusted_keys_first_matches(): void
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
    public function test_multiple_trusted_keys_second_matches(): void
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
    public function test_no_trusted_keys_match(): void
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
    public function test_nonexistent_archive_returns_failed(): void
    {
        $config = new ThemesConfig();
        $verifier = new ThemeProvenanceVerifier($config, new NullLogger());

        $result = $verifier->verify($this->tmpDir . '/nonexistent.zip');

        self::assertFalse($result->hashValid);
        self::assertNotNull($result->error);
    }

    // -- Invalid signature format ---------------------------------------------

    #[Test]
    public function test_invalid_signature_format_returns_failed(): void
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
    public function test_malformed_public_key_skipped_gracefully(): void
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
