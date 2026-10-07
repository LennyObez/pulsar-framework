<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwksEndpoint;
use Pulsar\Security\Crypto\KeyRingInterface;

#[CoversClass(JwksEndpoint::class)]
final class JwksEndpointTest extends TestCase
{
    private string $rsaPem1;
    private string $rsaPem2;

    protected function setUp(): void
    {
        $this->rsaPem1 = self::generateRsaPrivateKeyPem();
        $this->rsaPem2 = self::generateRsaPrivateKeyPem();
    }

    #[Test]
    public function jwksDocumentReturnsKeysArray(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn([
            'key-1' => $this->rsaPem1,
            'key-2' => $this->rsaPem2,
        ]);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        self::assertArrayHasKey('keys', $doc);
        self::assertCount(2, $doc['keys']);
    }

    #[Test]
    public function eachKeyHasRequiredRsaFields(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn(['kid-abc' => $this->rsaPem1]);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        $key = $doc['keys'][0];
        self::assertSame('RSA', $key['kty']);
        self::assertSame('kid-abc', $key['kid']);
        self::assertSame('sig', $key['use']);
        self::assertSame('RS256', $key['alg']);
        self::assertArrayHasKey('n', $key);
        self::assertArrayHasKey('e', $key);
        self::assertNotEmpty($key['n']);
        self::assertNotEmpty($key['e']);
    }

    #[Test]
    public function jwksDocumentWithNoKeysReturnsEmptyArray(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn([]);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        self::assertSame([], $doc['keys']);
    }

    #[Test]
    public function nonRsaKeyMaterialIsSkippedFromJwks(): void
    {
        // SEC-OIDC-01: a single bad entry (e.g. legacy HMAC bytes) must not
        // poison discovery — the endpoint silently skips entries it cannot
        // parse as RSA private keys.
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn([
            'legacy-hmac' => 'raw-hmac-bytes-not-a-pem',
            'good-rsa' => $this->rsaPem1,
        ]);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        self::assertCount(1, $doc['keys']);
        self::assertSame('good-rsa', $doc['keys'][0]['kid']);
    }

    #[Test]
    public function privateKeyMaterialIsNeverExposed(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn(['k1' => $this->rsaPem1]);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        $json = json_encode($doc);
        self::assertIsString($json);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $json);
        self::assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $json);
        self::assertStringNotContainsString($this->rsaPem1, $json);
    }

    private static function generateRsaPrivateKeyPem(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $pem);

        return $pem;
    }
}
