<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

use function array_keys;
use function array_map;
use function in_array;
use function is_string;

/**
 * Detects configuration keys that a typed config DTO does not recognize.
 *
 * A silently-ignored config key is a latent misconfiguration: `security.session.handler`
 * misspelled as `driver`, or `secure` as `secur`, leaves the intended value
 * unset with no error. This is the single, reused primitive every
 * {@see ReportsUnknownKeys} DTO calls, so the good behaviour that already
 * existed for the cache config applies uniformly across the config surface.
 * @api
 */
#[Api(since: '1.0.0')]
final class UnknownKeys
{
    /**
     * Return the keys present in $data that are not in $known, preserving order.
     *
     * Only string keys are considered — a config array is a string-keyed map, and a
     * stray integer key is a structural error handled elsewhere, not an "unknown key".
     *
     * @param array<array-key, mixed> $data  The raw config array as written on disk.
     * @param list<string>            $known  The keys the DTO's fromArray() actually reads.
     * @return list<string>
     */
    public static function collect(array $data, array $known): array
    {
        $unknown = [];

        foreach (array_keys($data) as $key) {
            if (is_string($key) && !in_array($key, $known, true)) {
                $unknown[] = $key;
            }
        }

        return $unknown;
    }

    /**
     * Prefix a nested section's unknown keys with its config path, so a parent can
     * fold a child's report into its own: `session` + `driver` reads as
     * `session.driver`, and the operator is pointed at the exact line to fix.
     *
     * A child that does not implement {@see ReportsUnknownKeys} contributes
     * nothing, so a parent can aggregate uniformly over its sub-sections without
     * knowing which of them report.
     *
     * @param string  $prefix The child's key in the parent array (e.g. 'csrf').
     * @param ?object $child  The constructed child DTO, or null when absent.
     * @return list<string>
     */
    public static function nested(string $prefix, ?object $child): array
    {
        if (!$child instanceof ReportsUnknownKeys) {
            return [];
        }

        return array_map(
            static fn(string $key): string => $prefix . '.' . $key,
            $child->unknownConfigKeys(),
        );
    }

    /**
     * Fold a keyed collection of child DTOs — `database.connections.*`,
     * `storage.disks.*`, `queue.drivers.*` — into path-qualified keys such as
     * `connections.mysql.charsett`. The collection key is operator-chosen, so it
     * is echoed verbatim between the prefix and the child's own key.
     *
     * The key type is `array-key`, not `string`: PHP coerces a numeric-string array
     * key to an int, so a collection labelled by position — or by a name like "2" —
     * legitimately arrives with integer keys.
     *
     * @param string                   $prefix   The collection's key in the parent array.
     * @param array<array-key, object> $children The constructed child DTOs, keyed as on disk.
     * @return list<string>
     */
    public static function nestedEach(string $prefix, array $children): array
    {
        $unknown = [];

        foreach ($children as $name => $child) {
            foreach (self::nested($prefix . '.' . $name, $child) as $key) {
                $unknown[] = $key;
            }
        }

        return $unknown;
    }
}
