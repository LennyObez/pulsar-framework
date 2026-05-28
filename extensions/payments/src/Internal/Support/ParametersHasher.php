<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Support;

use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;

use function is_array;

/**
 * Canonical parameter hashing utility for idempotency.
 *
 * Produces deterministic SHA-256 hashes by recursively sorting array keys
 * and using stable JSON encoding.
 */
#[Internal]
final readonly class ParametersHasher
{
    /**
     * Produce a deterministic SHA-256 hash from operation parameters.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws JsonException
     */
    #[NoDiscard]
    public static function hash(string $operation, array $parameters): string
    {
        $canonical = ['_operation' => $operation, ...$parameters];
        self::sortRecursive($canonical);

        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json);
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function sortRecursive(array &$array): void
    {
        ksort($array);

        /** @var mixed $value */
        foreach ($array as &$value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                self::sortRecursive($value);
            }
        }
    }
}
