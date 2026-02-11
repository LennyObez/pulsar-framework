<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\JwkKey;
use Pulsar\Extension\SocialSso\Internal\Token\OpenSslJwtSignatureDriver;

#[CoversClass(OpenSslJwtSignatureDriver::class)]
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
    public function doesNotSupportHs256(): void
    {
        self::assertFalse($this->driver->supports('HS256'));
    }

    #[Test]
    public function doesNotSupportNone(): void
    {
        self::assertFalse($this->driver->supports('none'));
    }

    #[Test]
    public function doesNotSupportRs384(): void
    {
        self::assertFalse($this->driver->supports('RS384'));
    }

    #[Test]
    public function verifyReturnsFalseWhenNoPublicKeyPem(): void
    {
        $key = new JwkKey(kty: 'oct', kid: 'hmac-key', alg: 'HS256');

        $result = $this->driver->verify('header', 'payload', 'signature', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForUnsupportedAlgorithm(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'rsa-key', alg: 'RS384', parameters: [
            'n' => 'not-valid',
            'e' => 'also-not-valid',
        ]);

        $result = $this->driver->verify('header', 'payload', 'signature', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForInvalidRsaParams(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'bad-rsa', alg: 'RS256', parameters: [
            'n' => 'invalid-modulus',
            'e' => 'AQAB',
        ]);

        $result = $this->driver->verify('header', 'payload', 'bad-signature', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForInvalidEcParams(): void
    {
        $key = new JwkKey(kty: 'EC', kid: 'bad-ec', alg: 'ES256', parameters: [
            'crv' => 'P-256',
            'x' => 'invalid',
            'y' => 'invalid',
        ]);

        $result = $this->driver->verify('header', 'payload', 'short', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForKeyWithNullAlg(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'no-alg', alg: null, parameters: [
            'n' => 'invalid-modulus',
            'e' => 'AQAB',
        ]);

        $result = $this->driver->verify('header', 'payload', 'signature', $key);

        self::assertFalse($result);
    }
}
