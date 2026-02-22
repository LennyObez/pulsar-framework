<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;

use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function str_repeat;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class CryptoBench
{
    private MasterKey $masterKey;
    private Encryptor $encryptor;
    private string $aeadKey;
    private string $aeadNonce;
    private string $aeadPayload;
    private string $aeadAad;
    private string $hmacKey;
    private string $hmacMessage;
    private string $envelopePlaintext;

    public function setUp(): void
    {
        $this->masterKey = MasterKey::fromHex(
            'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
        );

        // AEAD: derive subkey for xchacha20poly1305
        $this->aeadKey = $this->masterKey->deriveSubKey(
            10,
            'que_aead',
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
        );
        $this->aeadNonce = str_repeat("\x00", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $this->aeadPayload = str_repeat('A', 1024);
        $this->aeadAad = 'benchmark|test|payload|1|corr-bench-001|1';

        // HMAC: test audit key (32 bytes)
        $this->hmacKey = '0123456789abcdef0123456789abcdef';
        $this->hmacMessage = 'audit.entry|action=user.login|subject=user:1001|resource=session:abc123'
            . '|outcome=success|timestamp=2026-01-15T10:30:00Z|correlation=corr-bench-001'
            . '|ip=192.168.1.100|module=auth|severity=info';

        // Envelope encryption
        $this->encryptor = Encryptor::fromMasterKey($this->masterKey);
        $this->envelopePlaintext = str_repeat('B', 512);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchAeadEncrypt1024b(): void
    {
        $result = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $this->aeadPayload,
            $this->aeadAad,
            $this->aeadNonce,
            $this->aeadKey,
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchHmacSign(): void
    {
        $result = Hmac::computeHex($this->hmacMessage, $this->hmacKey);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchEnvelopeEncrypt(): void
    {
        $result = $this->encryptor->encrypt($this->envelopePlaintext);
    }

    // Tier C only — KDF budget enforced on controlled runner, not Tier A
    #[Subject]
    public function benchKdf(): void
    {
        $result = $this->masterKey->deriveSubKey(99, 'kdf_test');
    }
}
