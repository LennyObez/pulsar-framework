<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use FilesystemIterator;
use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use Serializable;
use SplFileInfo;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_values;
use function class_exists;
use function enum_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function sort;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/**
 * Composer classmap/PSR-4 scanner for safe unserialize() allowlist.
 *
 * Scans the Pulsar source tree to discover all classes eligible for
 * cache deserialization. Only readonly classes and backed enums in
 * eligible namespaces are included. Classes with dangerous magic
 * methods are excluded.
 */
#[Internal]
final class CacheAllowedClasses
{
    /** Namespaces eligible for cache deserialization. */
    private const array ELIGIBLE_NAMESPACES = [
        'Pulsar\\Config\\',
        'Pulsar\\Cache\\',
        'Pulsar\\Routing\\',
        'Pulsar\\Http\\',
    ];

    /**
     * Classes that are always allowed for cache deserialization regardless
     * of the readonly check. These are primary serialization targets that
     * cannot be readonly (e.g. mutable collections populated during boot).
     *
     * @var list<class-string>
     */
    private const array ALWAYS_ALLOWED = [
        ConfigRepository::class,
    ];

    /** Magic methods that make a class unsafe for deserialization. */
    private const array DANGEROUS_METHODS = [
        '__wakeup',
        '__destruct',
        '__serialize',
        '__unserialize',
    ];

    private const string FILENAME = 'allowed_classes.json';

    /**
     * Scan Pulsar source tree to discover all classes eligible for cache deserialization.
     *
     * @return list<class-string>
     *
     * @throws CacheException When an `ALWAYS_ALLOWED` class fails the
     *                       magic-method or Serializable safety check
     *                       (F26.2). Failing closed protects the cache
     *                       deserialization sink from gaining a gadget
     *                       chain via a future maintainer adding a
     *                       dangerous magic method to a whitelisted
     *                       class.
     * @throws ReflectionException
     */
    #[NoDiscard]
    public static function scan(string $vendorPath, string $srcPath): array
    {
        $candidates = self::discoverCandidates($vendorPath, $srcPath);

        // F26.2: ALWAYS_ALLOWED bypassed the eligibility check entirely,
        // so a future PR adding `__wakeup`, `__destruct`, `__serialize`,
        // or `__unserialize` to one of these classes (e.g.
        // ConfigRepository) would silently turn it into a deserialization
        // gadget. Re-apply the magic-method and Serializable checks
        // here — only the readonly-class restriction is waived.
        foreach (self::ALWAYS_ALLOWED as $alwaysAllowedClass) {
            self::assertAlwaysAllowedSafe($alwaysAllowedClass);
        }

        $allowed = self::ALWAYS_ALLOWED;

        foreach ($candidates as $className) {
            if (!in_array($className, $allowed, true) && self::isEligible($className)) {
                $allowed[] = $className;
            }
        }

        sort($allowed);

        return $allowed;
    }

    /**
     * Verify that an `ALWAYS_ALLOWED` class is still safe for cache
     * deserialization. Mirrors `isEligible()` minus the readonly /
     * backed-enum check — those classes are explicitly waived from
     * the readonly requirement, but every other guard still applies.
     *
     * @throws CacheException If the class implements Serializable or
     *                       defines a dangerous magic method.
     */
    private static function assertAlwaysAllowedSafe(string $className): void
    {
        if (!class_exists($className)) {
            throw CacheException::alwaysAllowedClassMissing($className);
        }

        // class_exists() above guarantees ReflectionClass cannot throw,
        // so no try/catch is needed.
        /** @var ReflectionClass<object> $ref */
        $ref = new ReflectionClass($className);

        if ($ref->implementsInterface(Serializable::class)) {
            throw CacheException::alwaysAllowedClassUnsafe(
                $className,
                'class implements Serializable, which exposes a custom unserialize() codepath outside the allowed-classes guard',
            );
        }

        if (self::hasDangerousMethods($ref)) {
            throw CacheException::alwaysAllowedClassUnsafe(
                $className,
                'class defines one of __wakeup, __destruct, __serialize, __unserialize — these run on every unserialize() even with allowed_classes set, so the class can be turned into a deserialization gadget',
            );
        }
    }

    /**
     * Load the saved allowlist from the cache directory.
     *
     * @return list<class-string>|null Null if not found or invalid.
     */
    #[NoDiscard]
    public static function load(string $cachePath): ?array
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $decoded = self::readJsonFile($path, 16);

        if ($decoded === null) {
            return null;
        }

        if (!array_is_list($decoded)) {
            return null;
        }

        /** @var list<class-string> $decoded */
        return $decoded;
    }

    /**
     * Read and decode a JSON file, returning null on any failure.
     *
     * @param int<1, 2147483647> $depth
     *
     * @return array<array-key, mixed>|null
     */
    private static function readJsonFile(string $path, int $depth = 512): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Save the allowlist to disk as JSON.
     *
     * @param list<class-string> $classes
     *
     * @throws JsonException
     */
    public static function save(string $cachePath, array $classes): void
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $json = json_encode($classes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * Discover candidate classes from Composer's classmap or PSR-4 scan.
     *
     * @return list<class-string>
     */
    private static function discoverCandidates(string $vendorPath, string $srcPath): array
    {
        $classmap = $vendorPath . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_classmap.php';

        if (is_file($classmap)) {
            /** @var array<class-string, string> $map */
            $map = require $classmap;

            return self::filterEligibleNamespaces(array_keys($map));
        }

        // Fallback: scan src/ directory for PHP files
        return self::scanDirectory($srcPath);
    }

    /**
     * Filter class names to only eligible namespaces.
     *
     * @param list<string> $classNames
     * @return list<class-string>
     */
    private static function filterEligibleNamespaces(array $classNames): array
    {
        /** @var list<class-string> */
        return array_values(array_filter(
            $classNames,
            static fn(string $className): bool => array_any(
                self::ELIGIBLE_NAMESPACES,
                static fn(string $namespace): bool => str_starts_with($className, $namespace),
            ),
        ));
    }

    /**
     * Check if a class is eligible for cache deserialization.
     *
     * Eligibility criteria:
     * 1. Class is either a readonly class or a backed enum
     * 2. Class does NOT implement Serializable
     * 3. Class does NOT define any dangerous magic methods
     *
     * @throws ReflectionException
     */
    private static function isEligible(string $className): bool
    {
        if (!class_exists($className) && !enum_exists($className)) {
            return false;
        }

        // Check if it's a backed enum
        if (enum_exists($className)) {
            $ref = new ReflectionEnum($className);

            return $ref->isBacked() && !self::hasDangerousMethods($ref);
        }

        /** @var ReflectionClass<object> $ref */
        $ref = new ReflectionClass($className);

        // Must be readonly
        if (!$ref->isReadOnly()) {
            return false;
        }

        // Must not implement Serializable
        if ($ref->implementsInterface(Serializable::class)) {
            return false;
        }

        // Must not have dangerous magic methods
        return !self::hasDangerousMethods($ref);
    }

    /**
     * Check if a class defines any dangerous magic methods.
     *
     * @param ReflectionClass<object> $ref
     */
    private static function hasDangerousMethods(ReflectionClass $ref): bool
    {
        return array_any(self::DANGEROUS_METHODS, static function (string $method) use ($ref): bool {
            if (!$ref->hasMethod($method)) {
                return false;
            }

            // Only count it if declared on this class (not inherited from a parent)
            return $ref->getMethod($method)->getDeclaringClass()->getName() === $ref->getName();
        });
    }

    /**
     * Scan a directory for PHP class files and extract FQCNs.
     *
     * @return list<class-string>
     */
    private static function scanDirectory(string $dir): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if ($content === false) {
                continue;
            }

            // Extract namespace and class/enum name from the file
            $namespace = '';
            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
                $namespace = $nsMatch[1];
            }

            $className = null;
            if (preg_match('/(?:readonly\s+)?(?:final\s+)?class\s+(\w+)/', $content, $classMatch)) {
                $className = $classMatch[1];
            } elseif (preg_match('/enum\s+(\w+)/', $content, $enumMatch)) {
                $className = $enumMatch[1];
            }

            if ($className !== null && $namespace !== '') {
                $fqcn = $namespace . '\\' . $className;

                foreach (self::ELIGIBLE_NAMESPACES as $eligible) {
                    if (str_starts_with($fqcn, $eligible)) {
                        /** @var class-string $fqcn */
                        $classes[] = $fqcn;
                        break;
                    }
                }
            }
        }

        return $classes;
    }
}
