<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\EInvoicing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\EInvoicing\InvoiceSigningService;

use function sodium_bin2hex;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function strlen;

final class InvoiceSigningServiceTest extends TestCase
{
    private InvoiceSigningService $service;
    private string $privateKeyHex;
    private string $publicKeyHex;

    protected function setUp(): void
    {
        $this->service = new InvoiceSigningService();

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyHex = sodium_bin2hex(sodium_crypto_sign_secretkey($keypair));
        $this->publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    }

    #[Test]
    public function signProducesSignedInvoice(): void
    {
        $xml = '<Invoice><ID>INV-001</ID></Invoice>';

        $signed = $this->service->sign($xml, $this->privateKeyHex);

        self::assertSame($xml, $signed->xml);
        self::assertSame(128, strlen($signed->signatureHex)); // Ed25519 sig = 64 bytes = 128 hex
        self::assertSame(64, strlen($signed->publicKeyHex)); // Ed25519 pk = 32 bytes = 64 hex
        self::assertInstanceOf(DateTimeImmutable::class, $signed->signedAt);
    }

    #[Test]
    public function signAndVerifyRoundtrip(): void
    {
        $xml = '<?xml version="1.0"?><Invoice><ID>INV-2026-000001</ID><Total>1050.00</Total></Invoice>';

        $signed = $this->service->sign($xml, $this->privateKeyHex);
        $verified = $this->service->verify($signed->xml, $signed->signatureHex, $signed->publicKeyHex);

        self::assertTrue($verified);
    }

    #[Test]
    public function tamperedXmlFailsVerification(): void
    {
        $xml = '<Invoice><ID>INV-001</ID><Total>100.00</Total></Invoice>';

        $signed = $this->service->sign($xml, $this->privateKeyHex);

        $tamperedXml = '<Invoice><ID>INV-001</ID><Total>999.00</Total></Invoice>';
        $verified = $this->service->verify($tamperedXml, $signed->signatureHex, $signed->publicKeyHex);

        self::assertFalse($verified);
    }

    #[Test]
    public function wrongPublicKeyFailsVerification(): void
    {
        $xml = '<Invoice><ID>INV-001</ID></Invoice>';

        $signed = $this->service->sign($xml, $this->privateKeyHex);

        // Generate a different keypair
        $otherKeypair = sodium_crypto_sign_keypair();
        $otherPublicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($otherKeypair));

        $verified = $this->service->verify($xml, $signed->signatureHex, $otherPublicKeyHex);

        self::assertFalse($verified);
    }

    #[Test]
    public function signDerivesCorrectPublicKey(): void
    {
        $xml = '<Invoice><ID>INV-001</ID></Invoice>';

        $signed = $this->service->sign($xml, $this->privateKeyHex);

        self::assertSame($this->publicKeyHex, $signed->publicKeyHex);
    }

    #[Test]
    public function signDifferentDocumentsProduceDifferentSignatures(): void
    {
        $xml1 = '<Invoice><ID>INV-001</ID></Invoice>';
        $xml2 = '<Invoice><ID>INV-002</ID></Invoice>';

        $signed1 = $this->service->sign($xml1, $this->privateKeyHex);
        $signed2 = $this->service->sign($xml2, $this->privateKeyHex);

        self::assertNotSame($signed1->signatureHex, $signed2->signatureHex);
    }

    #[Test]
    public function verifyWithEmptyXmlFailsForNonEmptySignature(): void
    {
        $xml = '<Invoice><ID>INV-001</ID></Invoice>';
        $signed = $this->service->sign($xml, $this->privateKeyHex);

        $verified = $this->service->verify('', $signed->signatureHex, $signed->publicKeyHex);

        self::assertFalse($verified);
    }

    #[Test]
    public function signEmptyXmlProducesValidSignature(): void
    {
        $signed = $this->service->sign('', $this->privateKeyHex);
        $verified = $this->service->verify('', $signed->signatureHex, $signed->publicKeyHex);

        self::assertTrue($verified);
    }

    #[Test]
    public function signLargeXmlDocument(): void
    {
        $xml = '<Invoice>' . str_repeat('<Line>Item description here</Line>', 1000) . '</Invoice>';

        $signed = $this->service->sign($xml, $this->privateKeyHex);
        $verified = $this->service->verify($signed->xml, $signed->signatureHex, $signed->publicKeyHex);

        self::assertTrue($verified);
    }
}
