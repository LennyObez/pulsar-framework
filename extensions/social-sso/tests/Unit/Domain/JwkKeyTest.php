<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\JwkKey;

final class JwkKeyTest extends TestCase
{
    #[Test]
    public function constructsWithMinimalParams(): void
    {
        $key = new JwkKey(kty: 'RSA');

        self::assertSame('RSA', $key->kty);
        self::assertNull($key->kid);
        self::assertNull($key->alg);
        self::assertNull($key->use);
        self::assertSame([], $key->parameters);
    }

    #[Test]
    public function constructsWithAllParams(): void
    {
        $key = new JwkKey(
            kty: 'EC',
            kid: 'key-1',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => 'x-val', 'y' => 'y-val'],
        );

        self::assertSame('EC', $key->kty);
        self::assertSame('key-1', $key->kid);
        self::assertSame('ES256', $key->alg);
        self::assertSame('sig', $key->use);
        self::assertSame('P-256', $key->parameters['crv']);
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForUnsupportedKeyType(): void
    {
        $key = new JwkKey(kty: 'oct');

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForRsaWithoutParameters(): void
    {
        $key = new JwkKey(kty: 'RSA');

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForEcWithoutParameters(): void
    {
        $key = new JwkKey(kty: 'EC');

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForEcMissingCrv(): void
    {
        $key = new JwkKey(kty: 'EC', parameters: [
            'x' => 'dGVzdA',
            'y' => 'dGVzdA',
        ]);

        self::assertNull($key->getPublicKeyPem());
    }
}
