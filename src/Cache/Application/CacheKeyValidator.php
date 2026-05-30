<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use InvalidArgumentException;
use Pulsar\Api\Internal;

use function is_string;
use function sprintf;
use function strlen;
use function strpbrk;
use function substr;

/**
 * Canonical key validation for all cache subsystems.
 *
 * Enforces PSR-6 key rules and a max-length constraint.
 * Used by CachePool, SimpleCache, TaggedCache, lock resource keys, and tag keys.
 */
#[Internal]
final class CacheKeyValidator
{
    /**
     * Maximum key length (lowest common denominator across drivers).
     */
    private const int MAX_KEY_LENGTH = 250;

    /**
     * Validate a single cache key.
     *
     * @throws InvalidArgumentException If the key is invalid
     */
    public static function validate(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty');
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Cache key must not exceed %d characters, got %d: "%s..."',
                self::MAX_KEY_LENGTH,
                strlen($key),
                substr($key, 0, 50),
            ));
        }

        if (strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidArgumentException(sprintf(
                'Cache key "%s" contains reserved characters (PSR-6: {}()/\\@:)',
                $key,
            ));
        }
    }

    /**
     * Validate multiple cache keys.
     *
     * @param array<array-key, mixed> $keys
     *
     * @throws InvalidArgumentException If any key is invalid
     */
    public static function validateMultiple(array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Cache key must be a string, got %s',
                    get_debug_type($key),
                ));
            }

            self::validate($key);
        }
    }
}
