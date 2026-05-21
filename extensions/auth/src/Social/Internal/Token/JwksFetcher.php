<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Internal\Token;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\Social\Domain\JwkKey;
use Pulsar\Extension\Auth\Social\Exception\SsoException;

use function array_find;
use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_string;
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

        // Use a custom error handler instead of `@` suppression so HTTP
        // failures don't pollute global PHP warnings while still allowing
        // legitimate runtime errors (config, OOM, etc.) to surface.
        $previousHandler = set_error_handler(static fn(): bool => true);

        try {
            $response = file_get_contents($jwksUri, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($response === false) {
            throw SsoException::jwksFetchFailed();
        }

        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw SsoException::jwksFetchFailed();
        }

        /** @var list<array<string, mixed>> $jwkKeys */
        $jwkKeys = $decoded['keys'];

        $keys = [];

        foreach ($jwkKeys as $keyData) {
            if (!isset($keyData['kty'])) {
                continue;
            }

            /** @var string $kty */
            $kty = is_string($keyData['kty']) ? $keyData['kty'] : '';

            /** @var string|null $kid */
            $kid = isset($keyData['kid']) && is_string($keyData['kid']) ? $keyData['kid'] : null;
            /** @var string|null $alg */
            $alg = isset($keyData['alg']) && is_string($keyData['alg']) ? $keyData['alg'] : null;
            /** @var string|null $use */
            $use = isset($keyData['use']) && is_string($keyData['use']) ? $keyData['use'] : null;

            $keys[] = new JwkKey(
                kty: $kty,
                kid: $kid,
                alg: $alg,
                use: $use,
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
        /** @var JwkKey|null $found */
        $found = array_find($keys, static fn(JwkKey $key): bool => $key->kid === $kid);

        if ($found !== null) {
            return $found;
        }

        // Key not found: clear cache and re-fetch for rotation support
        unset($this->cache[$jwksUri]);

        $keys = $this->fetchKeys($jwksUri);

        /** @var JwkKey|null $second */
        $second = array_find($keys, static fn(JwkKey $key): bool => $key->kid === $kid);

        return $second;
    }
}
