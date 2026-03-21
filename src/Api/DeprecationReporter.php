<?php

declare(strict_types=1);

namespace Pulsar\Api;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function is_array;
use function is_string;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Reports deprecation notices for symbols marked with #[Deprecated].
 *
 * Maintains a set of already-reported symbols to ensure each deprecation
 * is only triggered once per process.
 * @api
 */
#[Api(since: '1.0.0')]
final class DeprecationReporter
{
    /** @var array<string, true> */
    private static array $reported = [];

    /**
     * Check and report deprecation for a class.
     *
     * @param class-string $className
     */
    public static function checkClass(string $className): void
    {
        if (isset(self::$reported[$className])) {
            return;
        }

        $ref = new ReflectionClass($className);
        $attrs = $ref->getAttributes(Deprecated::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attrs === []) {
            return;
        }

        /** @var Deprecated $deprecated */
        $deprecated = $attrs[0]->newInstance();
        self::$reported[$className] = true;

        trigger_error($deprecated->message($className), E_USER_DEPRECATED);
    }

    /**
     * Check and report deprecation for a method.
     *
     * @param class-string $className
     */
    public static function checkMethod(string $className, string $methodName): void
    {
        $key = "$className::$methodName";

        if (isset(self::$reported[$key])) {
            return;
        }

        $ref = new ReflectionMethod($className, $methodName);
        $attrs = $ref->getAttributes(Deprecated::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attrs === []) {
            return;
        }

        /** @var Deprecated $deprecated */
        $deprecated = $attrs[0]->newInstance();
        self::$reported[$key] = true;

        trigger_error($deprecated->message($key), E_USER_DEPRECATED);
    }

    /**
     * Reset the reported set (for testing).
     */
    public static function reset(): void
    {
        self::$reported = [];
    }

    /**
     * Get all deprecation reports from the current snapshot.
     *
     * @param array<string, mixed> $snapshot API snapshot data
     * @return list<array{symbol: string, since: string, removeIn: string, replacement: string}>
     */
    public static function fromSnapshot(array $snapshot): array
    {
        $reports = [];

        foreach ($snapshot as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $deprecated = $entry['deprecated'] ?? null;

            if (!is_array($deprecated)) {
                continue;
            }

            $reports[] = [
                'symbol' => is_string($entry['name'] ?? null) ? $entry['name'] : 'unknown',
                'since' => is_string($deprecated['since'] ?? null) ? $deprecated['since'] : '',
                'removeIn' => is_string($deprecated['removeIn'] ?? null) ? $deprecated['removeIn'] : '',
                'replacement' => is_string($deprecated['replacement'] ?? null) ? $deprecated['replacement'] : '',
            ];
        }

        return $reports;
    }
}
