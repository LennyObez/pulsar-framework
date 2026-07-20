<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

use function array_keys;
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
}
