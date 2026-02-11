<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Internal\Token\JwksFetcher;

#[CoversClass(JwksFetcher::class)]
final class JwksFetcherTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'jwks_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function fetchKeysReturnsJwkKeysFromValidJwks(): void
    {
        $jwks = json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'key-1',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'n' => 'modulus',
                    'e' => 'AQAB',
                ],
                [
                    'kty' => 'EC',
                    'kid' => 'key-2',
                    'alg' => 'ES256',
                    'use' => 'sig',
                ],
            ],
        ]);

        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $keys = $fetcher->fetchKeys($this->tempFile);

        self::assertCount(2, $keys);
        self::assertSame('RSA', $keys[0]->kty);
        self::assertSame('key-1', $keys[0]->kid);
        self::assertSame('RS256', $keys[0]->alg);
        self::assertSame('sig', $keys[0]->use);
        self::assertSame('EC', $keys[1]->kty);
        self::assertSame('key-2', $keys[1]->kid);
    }

    #[Test]
    public function fetchKeysCachesResults(): void
    {
        $jwks = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1']]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();

        $first = $fetcher->fetchKeys($this->tempFile);
        // Modify file - cache should still return first result
        file_put_contents($this->tempFile, json_encode(['keys' => [['kty' => 'EC', 'kid' => 'k2']]]));
        $second = $fetcher->fetchKeys($this->tempFile);

        self::assertSame('RSA', $second[0]->kty);
        self::assertSame($first, $second);
    }

    #[Test]
    public function fetchKeysSkipsKeysWithoutKty(): void
    {
        $jwks = json_encode([
            'keys' => [
                ['kid' => 'no-kty-key'],
                ['kty' => 'RSA', 'kid' => 'valid'],
                'not-an-array',
            ],
        ]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $keys = $fetcher->fetchKeys($this->tempFile);

        self::assertCount(1, $keys);
        self::assertSame('valid', $keys[0]->kid);
    }

    #[Test]
    public function fetchKeysThrowsOnInvalidJsonStructure(): void
    {
        file_put_contents($this->tempFile, '{"not_keys": []}');

        $fetcher = new JwksFetcher();

        $this->expectException(SsoException::class);

        $fetcher->fetchKeys($this->tempFile);
    }

    #[Test]
    public function fetchKeysThrowsOnUnreachableUri(): void
    {
        $fetcher = new JwksFetcher();

        $this->expectException(SsoException::class);

        $fetcher->fetchKeys('/nonexistent/path/jwks.json');
    }

    #[Test]
    public function fetchKeyReturnsMatchingKey(): void
    {
        $jwks = json_encode([
            'keys' => [
                ['kty' => 'RSA', 'kid' => 'a'],
                ['kty' => 'RSA', 'kid' => 'b'],
            ],
        ]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $key = $fetcher->fetchKey($this->tempFile, 'b');

        self::assertNotNull($key);
        self::assertSame('b', $key->kid);
    }

    #[Test]
    public function fetchKeyReturnsNullForMissingKid(): void
    {
        $jwks = json_encode([
            'keys' => [
                ['kty' => 'RSA', 'kid' => 'a'],
            ],
        ]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $key = $fetcher->fetchKey($this->tempFile, 'nonexistent');

        self::assertNull($key);
    }

    #[Test]
    public function fetchKeyHandlesKeyWithOptionalFields(): void
    {
        $jwks = json_encode([
            'keys' => [
                ['kty' => 'OKP', 'kid' => 'ed-key'],
            ],
        ]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $key = $fetcher->fetchKey($this->tempFile, 'ed-key');

        self::assertNotNull($key);
        self::assertSame('OKP', $key->kty);
        self::assertNull($key->alg);
        self::assertNull($key->use);
    }
}
