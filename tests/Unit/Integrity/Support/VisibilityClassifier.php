<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use ReflectionClass;

/**
 * Classifies class visibility based on #[Api] and #[Internal] attributes.
 *
 * Visibility rules (per CLAUDE.md / PUBLIC_API.md):
 * - #[Api]      → PublicApi: safe for cross-module consumption
 * - #[Internal] → ExplicitInternal: forbidden cross-module
 * - Neither     → ImplicitInternal: forbidden cross-module
 */
final class VisibilityClassifier
{
    private const int PUBLIC_API = 0;
    private const int EXPLICIT_INTERNAL = 1;
    private const int IMPLICIT_INTERNAL = 2;

    /** @var array<string, self::PUBLIC_API|self::EXPLICIT_INTERNAL|self::IMPLICIT_INTERNAL> */
    private static array $cache = [];

    /**
     * Check if a class is safe for cross-module consumption.
     *
     * Returns true only if the class has the #[Api] attribute.
     */
    public static function isCrossModuleSafe(string $fqcn): bool
    {
        return self::classify($fqcn) === self::PUBLIC_API;
    }

    /**
     * Classify a class's visibility.
     *
     * @return self::PUBLIC_API|self::EXPLICIT_INTERNAL|self::IMPLICIT_INTERNAL
     */
    private static function classify(string $fqcn): int
    {
        if (isset(self::$cache[$fqcn])) {
            return self::$cache[$fqcn];
        }

        if (!class_exists($fqcn) && !interface_exists($fqcn) && !enum_exists($fqcn)) {
            // Cannot reflect — treat as implicit internal
            return self::$cache[$fqcn] = self::IMPLICIT_INTERNAL;
        }

        $ref = new ReflectionClass($fqcn);

        if ($ref->getAttributes(Api::class) !== []) {
            return self::$cache[$fqcn] = self::PUBLIC_API;
        }

        if ($ref->getAttributes(Internal::class) !== []) {
            return self::$cache[$fqcn] = self::EXPLICIT_INTERNAL;
        }

        return self::$cache[$fqcn] = self::IMPLICIT_INTERNAL;
    }

    /**
     * Reset the classification cache (for testing).
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
