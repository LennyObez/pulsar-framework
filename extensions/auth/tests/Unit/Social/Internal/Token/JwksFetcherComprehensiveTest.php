<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Internal\Token;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Domain\JwkKey;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use Pulsar\Extension\Auth\Social\Internal\Token\JwksFetcher;
use ReflectionProperty;

use function file_exists;
use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Comprehensive tests for JwksFetcher covering cache busting,
 * key rotation re-fetch, edge cases in key parsing, and concurrent
 * URI management.
 */
#[CoversClass(JwksFetcher::class)]
final class JwksFetcherComprehensiveTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'jwks_comp_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * Pre-seed the JwksFetcher's internal cache.
     *
     * @param array<string, list<JwkKey>> $cacheData
     */
    private function seedCache(JwksFetcher $fetcher, array $cacheData): void
    {
        $cache = new ReflectionProperty(JwksFetcher::class, 'cache');
        $cache->setValue($fetcher, $cacheData);
    }

    #[Test]
    public function fetchKeyBustsCacheWhenKidNotFoundThenRetrievesFromUpdatedFile(): void
    {
        // Initial JWKS with key-a only
        $jwks = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'key-a']]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();

        // Pre-populate cache with old data
        $this->seedCache($fetcher, [
            $this->tempFile => [new JwkKey(kty: 'RSA', kid: 'key-old')],
        ]);

        // Now update file to include key-b
        $updatedJwks = json_encode(['keys' => [
            ['kty' => 'RSA', 'kid' => 'key-a'],
            ['kty' => 'RSA', 'kid' => 'key-b'],
        ]]);
        file_put_contents($this->tempFile, $updatedJwks);

        // fetchKey for 'key-b' should bust cache and re-fetch
        $result = $fetcher->fetchKey($this->tempFile, 'key-b');

        self::assertNotNull($result);
        self::assertSame('key-b', $result->kid);
    }

    #[Test]
    public function fetchKeyReturnsNullAfterCacheBustWhenKidStillNotFound(): void
    {
        $jwks = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'key-a']]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();

        // First call populates cache
        $fetcher->fetchKeys($this->tempFile);

        // fetchKey for non-existent kid will clear cache, re-fetch, still not find
        $result = $fetcher->fetchKey($this->tempFile, 'key-nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function fetchKeysFromCacheDoesNotReadFileAgain(): void
    {
        $jwks = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'cached']]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();

        // First call populates cache
        $firstResult = $fetcher->fetchKeys($this->tempFile);
        self::assertCount(1, $firstResult);

        // Delete the file - cache should still serve data
        unlink($this->tempFile);

        $secondResult = $fetcher->fetchKeys($this->tempFile);
        self::assertSame($firstResult, $secondResult);
    }

    #[Test]
    public function fetchKeyReturnsFirstMatchingKid(): void
    {
        $jwks = json_encode(['keys' => [
            ['kty' => 'RSA', 'kid' => 'dup', 'alg' => 'RS256'],
            ['kty' => 'EC', 'kid' => 'dup', 'alg' => 'ES256'],
        ]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $result = $fetcher->fetchKey($this->tempFile, 'dup');

        self::assertNotNull($result);
        // array_find returns the first match
        self::assertSame('RSA', $result->kty);
    }

    #[Test]
    public function fetchKeysHandlesKeyWithAllOptionalFieldsNull(): void
    {
        $jwks = json_encode(['keys' => [
            ['kty' => 'OKP'],
        ]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $keys = $fetcher->fetchKeys($this->tempFile);

        self::assertCount(1, $keys);
        self::assertSame('OKP', $keys[0]->kty);
        self::assertNull($keys[0]->kid);
        self::assertNull($keys[0]->alg);
        self::assertNull($keys[0]->use);
    }

    #[Test]
    public function fetchKeysThrowsOnPlainTextResponse(): void
    {
        file_put_contents($this->tempFile, 'Not a JSON response');

        $fetcher = new JwksFetcher();

        $this->expectException(JsonException::class);

        $fetcher->fetchKeys($this->tempFile);
    }

    #[Test]
    public function fetchKeysThrowsOnJsonArray(): void
    {
        // A valid JSON array (no 'keys' key)
        file_put_contents($this->tempFile, '[1,2,3]');

        $fetcher = new JwksFetcher();

        $this->expectException(SsoException::class);

        $fetcher->fetchKeys($this->tempFile);
    }

    #[Test]
    public function fetchKeysThrowsWhenKeysValueIsNotArray(): void
    {
        file_put_contents($this->tempFile, '{"keys": "not-an-array"}');

        $fetcher = new JwksFetcher();

        $this->expectException(SsoException::class);

        $fetcher->fetchKeys($this->tempFile);
    }

    #[Test]
    public function multipleDifferentUrisCachedIndependently(): void
    {
        $tempFile2 = tempnam(sys_get_temp_dir(), 'jwks_comp2_');

        try {
            $jwks1 = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'from-uri-1']]]);
            $jwks2 = json_encode(['keys' => [['kty' => 'EC', 'kid' => 'from-uri-2']]]);

            file_put_contents($this->tempFile, $jwks1);
            file_put_contents($tempFile2, $jwks2);

            $fetcher = new JwksFetcher();

            $keys1 = $fetcher->fetchKeys($this->tempFile);
            $keys2 = $fetcher->fetchKeys($tempFile2);

            self::assertCount(1, $keys1);
            self::assertSame('RSA', $keys1[0]->kty);
            self::assertSame('from-uri-1', $keys1[0]->kid);

            self::assertCount(1, $keys2);
            self::assertSame('EC', $keys2[0]->kty);
            self::assertSame('from-uri-2', $keys2[0]->kid);
        } finally {
            if (file_exists($tempFile2)) {
                unlink($tempFile2);
            }
        }
    }

    #[Test]
    public function fetchKeysReturnsEmptyWhenAllKeysLackKty(): void
    {
        $jwks = json_encode(['keys' => [
            ['kid' => 'no-kty-1'],
            ['kid' => 'no-kty-2', 'alg' => 'RS256'],
        ]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $keys = $fetcher->fetchKeys($this->tempFile);

        self::assertSame([], $keys);
    }

    #[Test]
    public function fetchKeysPreservesFullParametersMap(): void
    {
        $jwks = json_encode(['keys' => [
            [
                'kty' => 'RSA',
                'kid' => 'full',
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => 'modulus-value',
                'e' => 'AQAB',
                'x5c' => ['cert-chain'],
            ],
        ]]);
        file_put_contents($this->tempFile, $jwks);

        $fetcher = new JwksFetcher();
        $keys = $fetcher->fetchKeys($this->tempFile);

        self::assertCount(1, $keys);
        self::assertSame('modulus-value', $keys[0]->parameters['n']);
        self::assertSame('AQAB', $keys[0]->parameters['e']);
        self::assertSame(['cert-chain'], $keys[0]->parameters['x5c']);
    }
}
