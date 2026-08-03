<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Signing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Signing\ArtifactSigner;
use RuntimeException;

use function file_put_contents;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(ArtifactSigner::class)]
final class ArtifactSignerTest extends TestCase
{
    private ArtifactSigner $signer;
    private string $secretKey;
    private string $publicKey;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->signer = new ArtifactSigner();

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use — test cleanup of self-created temp files
                unlink($file);
            }
        }
    }

    #[Test]
    public function signAndVerifyRoundtripSucceeds(): void
    {
        $file = $this->createTempFile('Hello, this is release artifact content.');

        $signature = $this->signer->sign($file, $this->secretKey);

        self::assertNotEmpty($signature);

        $isValid = $this->signer->verify($file, $signature, $this->publicKey);

        self::assertTrue($isValid);
    }

    #[Test]
    public function verifyFailsWhenFileContentTampered(): void
    {
        $file = $this->createTempFile('Original content before signing.');

        $signature = $this->signer->sign($file, $this->secretKey);

        // Tamper with the file
        file_put_contents($file, 'TAMPERED content after signing.');

        $isValid = $this->signer->verify($file, $signature, $this->publicKey);

        self::assertFalse($isValid);
    }

    #[Test]
    public function verifyFailsWithWrongPublicKey(): void
    {
        $file = $this->createTempFile('Content signed with one key.');

        $signature = $this->signer->sign($file, $this->secretKey);

        // Generate a different keypair
        $otherKeypair = sodium_crypto_sign_keypair();
        $otherPublicKey = sodium_crypto_sign_publickey($otherKeypair);

        $isValid = $this->signer->verify($file, $signature, $otherPublicKey);

        self::assertFalse($isValid);
    }

    #[Test]
    public function verifyFailsWithCorruptedSignature(): void
    {
        $file = $this->createTempFile('Content for corrupt sig test.');

        $signature = $this->signer->sign($file, $this->secretKey);

        // Corrupt the signature by replacing the first character with a
        // different one. Guard the rare case where the signature already
        // starts with the replacement char (which would leave it unchanged
        // and valid — a ~1-in-64 flake for base64 signatures).
        $corrupted = ($signature[0] === 'X' ? 'Y' : 'X') . substr($signature, 1);

        $isValid = $this->signer->verify($file, $corrupted, $this->publicKey);

        self::assertFalse($isValid);
    }

    #[Test]
    public function verifyFailsWithTruncatedSignature(): void
    {
        $file = $this->createTempFile('Content for truncated sig test.');

        (void) $this->signer->sign($file, $this->secretKey);

        // Use a too-short signature
        $isValid = $this->signer->verify($file, 'dG9vc2hvcnQ=', $this->publicKey);

        self::assertFalse($isValid);
    }

    #[Test]
    public function signThrowsForNonexistentFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        (void) $this->signer->sign('/nonexistent/path/artifact.phar', $this->secretKey);
    }

    #[Test]
    public function verifyThrowsForNonexistentFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        $this->signer->verify('/nonexistent/path/artifact.phar', 'sig', $this->publicKey);
    }

    #[Test]
    public function signThrowsForPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Path traversal');

        (void) $this->signer->sign('/some/path/../../../etc/passwd', $this->secretKey);
    }

    #[Test]
    public function verifyThrowsForPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Path traversal');

        $this->signer->verify('/some/path/../../etc/passwd', 'sig', $this->publicKey);
    }

    #[Test]
    public function signThrowsForInvalidSecretKeyLength(): void
    {
        $file = $this->createTempFile('test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Invalid secret key length');

        (void) $this->signer->sign($file, 'too-short-key');
    }

    #[Test]
    public function verifyThrowsForInvalidPublicKeyLength(): void
    {
        $file = $this->createTempFile('test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Invalid public key length');

        $this->signer->verify($file, 'sig', 'too-short-key');
    }

    #[Test]
    public function signProducesBase64EncodedOutput(): void
    {
        $file = $this->createTempFile('Base64 test content.');

        $signature = $this->signer->sign($file, $this->secretKey);

        // Verify it decodes cleanly from base64
        $decoded = base64_decode($signature, true);
        self::assertNotFalse($decoded);
        self::assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($decoded));
    }

    #[Test]
    public function signaturesDifferForDifferentFiles(): void
    {
        $file1 = $this->createTempFile('File content A');
        $file2 = $this->createTempFile('File content B');

        $sig1 = $this->signer->sign($file1, $this->secretKey);
        $sig2 = $this->signer->sign($file2, $this->secretKey);

        self::assertNotSame($sig1, $sig2);
    }

    #[Test]
    public function signatureIsDeterministicForSameContent(): void
    {
        $file = $this->createTempFile('Deterministic test content.');

        $sig1 = $this->signer->sign($file, $this->secretKey);
        $sig2 = $this->signer->sign($file, $this->secretKey);

        // Ed25519 is deterministic for same key + message
        self::assertSame($sig1, $sig2);
    }

    #[Test]
    public function signHandlesEmptyFile(): void
    {
        $file = $this->createTempFile('');

        $signature = $this->signer->sign($file, $this->secretKey);

        self::assertNotEmpty($signature);

        $isValid = $this->signer->verify($file, $signature, $this->publicKey);
        self::assertTrue($isValid);
    }

    #[Test]
    public function signHandlesBinaryContent(): void
    {
        $binaryContent = random_bytes(1024);
        $file = $this->createTempFile($binaryContent);

        $signature = $this->signer->sign($file, $this->secretKey);

        $isValid = $this->signer->verify($file, $signature, $this->publicKey);
        self::assertTrue($isValid);
    }

    private function createTempFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pulsar_sign_test_');
        self::assertIsString($file);

        file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return $file;
    }
}
