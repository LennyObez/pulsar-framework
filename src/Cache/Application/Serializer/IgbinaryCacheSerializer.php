<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Serializer;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\CacheException;

use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function is_object;
use function restore_error_handler;
use function set_error_handler;

/**
 * igbinary-backed cache serializer for scalars and arrays only (opt-in).
 *
 * Compared to the JSON default it preserves integer array keys and float
 * fidelity exactly and produces a compact binary encoding that is markedly
 * faster on large arrays. It is deliberately DATA-ONLY, enforced at runtime on
 * both write and read: igbinary_unserialize() has no `allowed_classes`
 * equivalent, so it cannot match PhpCacheSerializer's fail-closed allowlist —
 * any object it reconstructed would have run __wakeup()/__unserialize() before
 * a post-hoc check could reject it, which is an object-injection surface, not
 * a safeguard. Objects therefore stay on the `php` serializer and its
 * allowlist; this serializer refuses them outright, including nested inside
 * arrays, and refuses any foreign blob that decodes to one.
 */
#[Internal]
final readonly class IgbinaryCacheSerializer implements CacheSerializerInterface
{
    public function serialize(mixed $value): string
    {
        if ($this->containsObject($value)) {
            throw CacheException::serializationFailed(
                'The igbinary cache serializer stores scalars and arrays only. '
                . 'To cache objects, configure `serializer: \'php\'` with an '
                . '`allowed_classes` allowlist on the pool and review the '
                . 'security notes in docs/caching.md.',
            );
        }

        $encoded = igbinary_serialize($value);

        if ($encoded === null) {
            throw CacheException::serializationFailed('igbinary_serialize() failed to encode the value.');
        }

        return $encoded;
    }

    public function deserialize(string $data): mixed
    {
        // igbinary emits warnings on malformed input; scope-silence them the
        // same way PhpCacheSerializer does and detect failure via the
        // null-return + null-roundtrip disambiguation below.
        set_error_handler(static fn(): bool => true);

        try {
            /** @var mixed $value */
            $value = igbinary_unserialize($data);
        } finally {
            restore_error_handler();
        }

        if ($value === null && $data !== igbinary_serialize(null)) {
            throw CacheException::serializationFailed('Failed to igbinary-deserialize cached data');
        }

        // Data-only is enforced on read as well: a blob written by something
        // else into a shared backend could encode objects. This check stops
        // such an object from ever reaching a consumer (type confusion,
        // gadget propagation). It cannot undo __wakeup() side effects that
        // igbinary_unserialize() already ran for a hostile blob — keeping
        // hostile blobs out is the job of pool isolation, not this guard —
        // which is exactly why object caching belongs to the allowlisted
        // `php` serializer.
        if ($this->containsObject($value)) {
            throw CacheException::serializationFailed(
                'Refusing to deserialize cached data containing an object; '
                . 'the igbinary serializer is data-only (scalars and arrays).',
            );
        }

        return $value;
    }

    /**
     * Whether the value is or transitively contains an object.
     */
    private function containsObject(mixed $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        if (is_array($value)) {
            /** @var mixed $item */
            foreach ($value as $item) {
                if ($this->containsObject($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
