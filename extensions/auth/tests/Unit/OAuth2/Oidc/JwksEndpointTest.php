<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwksEndpoint;
use Pulsar\Security\Crypto\KeyRingInterface;

final class JwksEndpointTest extends TestCase
{
    #[Test]
    public function jwksDocumentReturnsKeysArray(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn([
            'key-1' => 'secret-bytes-1',
            'key-2' => 'secret-bytes-2',
        ]);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        self::assertArrayHasKey('keys', $doc);
        self::assertCount(2, $doc['keys']);
    }

    #[Test]
    public function eachKeyHasRequiredFields(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn(['kid-abc' => 'bytes']);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        $key = $doc['keys'][0];
        self::assertSame('oct', $key['kty']);
        self::assertSame('kid-abc', $key['kid']);
        self::assertSame('sig', $key['use']);
        self::assertSame('HS256', $key['alg']);
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
    public function keyMaterialIsNeverExposed(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('all')->willReturn(['k1' => 'super-secret-key-material']);

        $endpoint = new JwksEndpoint($keyRing);
        $doc = $endpoint->jwksDocument();

        $json = json_encode($doc);
        self::assertStringNotContainsString('super-secret-key-material', $json);
    }
}
