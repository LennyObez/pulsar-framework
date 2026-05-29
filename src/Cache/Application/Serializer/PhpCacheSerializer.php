<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Serializer;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\CacheException;
use Throwable;

use function is_array;
use function is_object;
use function serialize;
use function unserialize;

/**
 * PHP native serializer (opt-in, for caching objects).
 *
 * By default, no classes are allowed during deserialization.
 * Pass an explicit allowlist to the constructor to permit specific classes.
 */
#[Internal]
final readonly class PhpCacheSerializer implements CacheSerializerInterface
{
    /**
     * @param list<class-string> $allowedClasses Classes allowed during deserialization
     */
    public function __construct(
        private array $allowedClasses = [],
    ) {}

    public function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (Throwable $e) {
            throw CacheException::serializationFailed($e->getMessage(), $e);
        }
    }

    public function deserialize(string $data): mixed
    {
        // CWE-502: this opt-in object serializer cannot avoid unserialize(). It
        // is hardened instead — allowed_classes defaults to `false` (no classes),
        // the input is trusted cache-backend data (not request input), and any
        // disallowed class that slips through is rejected fail-closed by
        // containsIncompleteClass() below.
        try {
            /** @var mixed $result */
            $result = unserialize($data, [ // nosemgrep
                'allowed_classes' => $this->allowedClasses === [] ? false : $this->allowedClasses,
            ]);
        } catch (Throwable $e) {
            throw CacheException::serializationFailed($e->getMessage(), $e);
        }

        if ($result === false && $data !== 'b:0;') {
            throw CacheException::serializationFailed('Failed to unserialize cached data');
        }

        // Fail closed on object injection: unserialize() with a class allowlist
        // (or `false`) does not reject disallowed objects — it silently downgrades
        // them to __PHP_Incomplete_Class. Surface that as an error instead of
        // handing the caller a half-constructed object it would misuse. Trusted
        // classes must be added to the allowlist explicitly.
        if (self::containsIncompleteClass($result)) {
            throw CacheException::serializationFailed(
                'Refusing to deserialize cached data containing a class outside the '
                . 'allowlist; add it to the serializer allowlist if it is trusted.',
            );
        }

        return $result;
    }

    /**
     * Recursively detect the __PHP_Incomplete_Class placeholders that
     * unserialize() substitutes for classes outside the allowlist, including
     * objects nested inside arrays or other (allowed) objects. The (array)
     * cast exposes properties of every visibility.
     */
    private static function containsIncompleteClass(mixed $value): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsIncompleteClass($item)) {
                    return true;
                }
            }

            return false;
        }

        if (is_object($value)) {
            foreach ((array) $value as $item) {
                if (self::containsIncompleteClass($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
