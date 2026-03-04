<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Internal\Token;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\JwkKey;
use Pulsar\Extension\SocialSso\Internal\Token\OpenSslJwtSignatureDriver;

final class OpenSslJwtSignatureDriverTest extends TestCase
{
    private OpenSslJwtSignatureDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new OpenSslJwtSignatureDriver();
    }

    #[Test]
    public function supportsRs256(): void
    {
        self::assertTrue($this->driver->supports('RS256'));
    }

    #[Test]
    public function supportsEs256(): void
    {
        self::assertTrue($this->driver->supports('ES256'));
    }

    #[Test]
    #[DataProvider('unsupportedAlgorithmsProvider')]
    public function doesNotSupportOtherAlgorithms(string $alg): void
    {
        self::assertFalse($this->driver->supports($alg));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedAlgorithmsProvider(): iterable
    {
        yield 'HS256' => ['HS256'];
        yield 'RS384' => ['RS384'];
        yield 'PS256' => ['PS256'];
        yield 'none' => ['none'];
        yield 'empty' => [''];
    }

    #[Test]
    public function verifyReturnsFalseWhenKeyHasNoPem(): void
    {
        $key = new JwkKey(
            kty: 'RSA',
            kid: 'test-kid',
            alg: 'RS256',
            use: 'sig',
            parameters: [],
        );

        $result = $this->driver->verify('header', 'payload', 'signature', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForUnsupportedAlgorithmInKey(): void
    {
        $key = new JwkKey(
            kty: 'OKP',
            kid: 'test-kid',
            alg: 'EdDSA',
            use: 'sig',
            parameters: [],
        );

        $result = $this->driver->verify('header', 'payload', 'signature', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForInvalidRs256Signature(): void
    {
        // Generate an RSA key for testing
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($privateKey === false) {
            self::markTestSkipped('RSA key generation not available');
        }

        $details = openssl_pkey_get_details($privateKey);

        if ($details === false) {
            self::markTestSkipped('Cannot extract RSA key details');
        }

        $pem = $details['key'];

        $key = new JwkKey(
            kty: 'RSA',
            kid: 'rsa-kid',
            alg: 'RS256',
            use: 'sig',
            parameters: ['pem' => $pem],
        );

        // Invalid signature
        $result = $this->driver->verify('header', 'payload', 'bad-signature', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyEs256ReturnsFalseForWrongLengthSignature(): void
    {
        // Generate an EC key
        $privateKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($privateKey === false) {
            self::markTestSkipped('EC key generation not available');
        }

        $details = openssl_pkey_get_details($privateKey);

        if ($details === false) {
            self::markTestSkipped('Cannot extract EC key details');
        }

        $pem = $details['key'];

        $key = new JwkKey(
            kty: 'EC',
            kid: 'ec-kid',
            alg: 'ES256',
            use: 'sig',
            parameters: ['pem' => $pem],
        );

        // Wrong-length signature (should be 64 bytes for P-256)
        $result = $this->driver->verify('header', 'payload', 'too-short', $key);

        self::assertFalse($result);
    }
}
