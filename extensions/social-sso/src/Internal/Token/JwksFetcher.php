<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Domain\JwkKey;
use Pulsar\Extension\SocialSso\Exception\SsoException;

use function array_find;
use function array_key_exists;
use function file_get_contents;
use function is_array;
use function json_decode;
use function stream_context_create;

use const JSON_THROW_ON_ERROR;

/**
 * Fetches and caches JWKS (JSON Web Key Sets) from provider endpoints.
 *
 * Maintains an in-memory cache to avoid redundant HTTP requests within the
 * same request lifecycle. Supports cache-busting for key rotation scenarios:
 * when a specific kid is not found, the cache is cleared and the JWKS is re-fetched.
 */
#[Internal]
final class JwksFetcher
{
    /** @var array<string, list<JwkKey>> */
    private array $cache = [];

    /**
     * Fetch all keys from a JWKS endpoint.
     *
     * @return list<JwkKey>
     *
     * @throws SsoException if the JWKS cannot be fetched or parsed
     */
    public function fetchKeys(string $jwksUri): array
    {
        if (array_key_exists($jwksUri, $this->cache)) {
            return $this->cache[$jwksUri];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($jwksUri, false, $context);

        if ($response === false) {
            throw SsoException::jwksFetchFailed();
        }

        /** @var array{keys?: list<array<string, mixed>>} $data */
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
            throw SsoException::jwksFetchFailed();
        }

        $keys = [];

        foreach ($data['keys'] as $keyData) {
            if (!is_array($keyData) || !isset($keyData['kty'])) {
                continue;
            }

            /** @var string $kty */
            $kty = $keyData['kty'];

            $keys[] = new JwkKey(
                kty: $kty,
                kid: isset($keyData['kid']) ? (string) $keyData['kid'] : null,
                alg: isset($keyData['alg']) ? (string) $keyData['alg'] : null,
                use: isset($keyData['use']) ? (string) $keyData['use'] : null,
                parameters: $keyData,
            );
        }

        $this->cache[$jwksUri] = $keys;

        return $keys;
    }

    /**
     * Fetch a specific key by kid from a JWKS endpoint.
     *
     * If the kid is not found on the first attempt, the cache for that URI
     * is cleared and the JWKS is re-fetched to handle key rotation. Returns
     * null if the key still cannot be found after re-fetching.
     */
    public function fetchKey(string $jwksUri, string $kid): ?JwkKey
    {
        $keys = $this->fetchKeys($jwksUri);
        $found = array_find($keys, static fn (JwkKey $key): bool => $key->kid === $kid);

        if ($found !== null) {
            return $found;
        }

        // Key not found — clear cache and re-fetch for rotation support
        unset($this->cache[$jwksUri]);

        $keys = $this->fetchKeys($jwksUri);

        return array_find($keys, static fn (JwkKey $key): bool => $key->kid === $kid);
    }
}
