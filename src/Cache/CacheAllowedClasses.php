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

use function array_fill_keys;
use function array_filter;
use function array_is_list;
use function array_keys;
use function array_values;
use function class_exists;
use function dirname;
use function enum_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function preg_match_all;
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
     * The framework's own src/ is always scanned (it owns every eligible
     * namespace); `$srcPaths` adds the project's roots, which come from its
     * composer PSR-4 map rather than an assumed `src/` layout. Non-existent
     * roots are skipped.
     *
     * @param list<string> $srcPaths Additional project source roots to union in.
     * @return list<class-string>
     *
     * @throws CacheException When an `ALWAYS_ALLOWED` class fails the
     *                       magic-method or Serializable safety check.
     *                       Failing closed protects the cache
     *                       deserialization sink from gaining a gadget
     *                       chain via a future maintainer adding a
     *                       dangerous magic method to a whitelisted
     *                       class.
     * @throws ReflectionException
     */
    #[NoDiscard]
    public static function scan(string $vendorPath, array $srcPaths): array
    {
        $candidates = self::discoverCandidates($vendorPath, $srcPaths);

        // ALWAYS_ALLOWED entries skip candidate discovery, so adding
        // `__wakeup`, `__destruct`, `__serialize`, or `__unserialize` to
        // one of these classes (e.g. ConfigRepository) would silently turn
        // it into a deserialization gadget. Re-apply the magic-method and
        // Serializable checks here — only the readonly-class restriction
        // is waived.
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
     * Build the cache deserialization allowlist: the namespace {@see scan()}
     * baseline unioned with the exact classes/enums present in each serialized
     * blob that will be written to the cache.
     *
     * The scan is namespace-scoped (Config, Cache, Routing, Http), but a
     * serialized ConfigRepository reaches config value objects that live in
     * feature namespaces — Api, Database, Mail, Tenancy, View, ... — which no
     * fixed namespace list reliably covers. Deriving the allowlist from the
     * actual serialized data makes it exact and complete regardless of
     * namespace, while the scan keeps a forward-compatible baseline for the
     * other cached artifacts (routes, container hints).
     *
     * @param list<string> $srcPaths Project source roots (composer PSR-4 map).
     * @return list<class-string>
     *
     * @throws CacheException When an ALWAYS_ALLOWED class or a serialized class
     *                       fails the deserialization-safety check.
     * @throws ReflectionException
     */
    #[NoDiscard]
    public static function forCache(string $vendorPath, array $srcPaths, string ...$serializedBlobs): array
    {
        $allowed = self::scan($vendorPath, $srcPaths);
        $seen = array_fill_keys($allowed, true);

        foreach ($serializedBlobs as $blob) {
            foreach (self::extractFromSerialized($blob) as $className) {
                if (!isset($seen[$className])) {
                    $seen[$className] = true;
                    $allowed[] = $className;
                }
            }
        }

        sort($allowed);

        return $allowed;
    }

    /**
     * Extract the exact set of classes and backed enums that appear in a
     * serialized string, verifying each is safe to unserialize.
     *
     * Namespace-agnostic and exact — the safe allowlist for what is actually
     * cached is derived from the data itself rather than guessed from a fixed
     * namespace list. Unknown classes (e.g. a stale serialization referencing a
     * removed class) are skipped; a present-but-unsafe class fails closed.
     *
     * @return list<class-string>
     *
     * @throws CacheException If a serialized class implements Serializable or
     *                       defines a dangerous magic method (a deserialization
     *                       gadget must never be silently allow-listed).
     */
    #[NoDiscard]
    public static function extractFromSerialized(string $serialized): array
    {
        // PHP emits O:len:"Class":... for objects and E:len:"Enum:case"; for
        // backed enums; both stop the class token at the closing quote or the
        // enum ':' separator.
        preg_match_all('/(?:O|E):\d+:"([^":]++)/', $serialized, $matches);

        $classes = [];
        $seen = [];

        foreach ($matches[1] as $className) {
            if (isset($seen[$className])) {
                continue;
            }

            $seen[$className] = true;

            if (!class_exists($className) && !enum_exists($className)) {
                continue;
            }

            self::assertSafeToDeserialize($className);
            /** @var class-string $className */
            $classes[] = $className;
        }

        sort($classes);

        return $classes;
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

        self::assertSafeToDeserialize($className);
    }

    /**
     * Verify a class is safe to unserialize under an `allowed_classes` guard:
     * it must neither implement Serializable (a custom unserialize() codepath)
     * nor define a magic method that runs on every unserialize() even when the
     * class is allow-listed. Fails closed — such a class is a deserialization
     * gadget the moment it is added to any allowlist.
     *
     * @param class-string $className Caller guarantees the class/enum exists.
     *
     * @throws CacheException
     */
    private static function assertSafeToDeserialize(string $className): void
    {
        // Callers guarantee the class exists, so ReflectionClass cannot throw.
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
     * @throws CacheException If the allowlist file cannot be written.
     * @throws JsonException
     */
    public static function save(string $cachePath, array $classes): void
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        $json = json_encode($classes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // A discarded return value would let a disk-full / permission failure
        // pass silently: doWarm() then hashes a missing (empty-string hash) or
        // stale file, producing a manifest whose allowed_classes_hash will not
        // match the file on the next load(). Fail loudly instead.
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw CacheException::writeFailure($path, 'failed to write allowed-classes file');
        }
    }

    /**
     * Discover candidate classes from the framework's own source tree, the
     * project's PSR-4 roots, and Composer's classmap.
     *
     * @param list<string> $srcPaths
     * @return list<class-string>
     */
    private static function discoverCandidates(string $vendorPath, array $srcPaths): array
    {
        // Every ELIGIBLE_NAMESPACES entry (Pulsar\Config|Cache|Routing|Http) is a
        // FRAMEWORK class, so the authoritative root is the framework's OWN src/,
        // resolved from this file rather than from the caller's layout. In an
        // installed application `basePath/src` is the APPLICATION's source dir —
        // or absent entirely when its PSR-4 root is e.g. `app/` — and contains no
        // Pulsar\* classes, so deriving the root from the caller would silently
        // empty the allowlist.
        //
        // Scanning the directory (rather than trusting autoload_classmap.php) also
        // keeps this independent of Composer's autoloader optimization: a
        // NON-optimized classmap (a plain `composer install` / `dump-autoload`, as
        // CI and dev use) lists almost no PSR-4 classes, so framework cache DTOs
        // such as Pulsar\Cache\CachedRoute would be dropped and every warm-cache
        // boot would fail with __PHP_Incomplete_Class. The classmap is unioned in
        // only as a defensive supplement for any eligible class shipped outside src/.
        $frameworkSrc = dirname(__DIR__);
        $candidates = self::scanDirectory($frameworkSrc);

        // Every caller-supplied project root (from the composer PSR-4 map) is
        // unioned in when it exists; a declared-but-absent root is skipped.
        foreach ($srcPaths as $srcPath) {
            if ($srcPath === '' || $srcPath === $frameworkSrc) {
                continue;
            }

            foreach (self::scanDirectory($srcPath) as $className) {
                $candidates[] = $className;
            }
        }

        $classmap = $vendorPath . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_classmap.php';

        if (is_file($classmap)) {
            /** @var array<class-string, string> $map */
            $map = require $classmap;

            foreach (self::filterEligibleNamespaces(array_keys($map)) as $className) {
                $candidates[] = $className;
            }
        }

        return array_values(array_unique($candidates));
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
        // A dangerous magic method counts whether declared on this class OR
        // inherited from a parent: PHP's unserialize() invokes the inherited
        // __wakeup / __destruct / __serialize / __unserialize when
        // reconstructing the subclass, so a subclass that merely inherits one
        // is just as exploitable as the parent. hasMethod() already walks the
        // inheritance chain.
        return array_any(
            self::DANGEROUS_METHODS,
            static fn(string $method): bool => $ref->hasMethod($method),
        );
    }

    /**
     * Scan a directory for PHP class files and extract FQCNs.
     *
     * @return list<class-string>
     */
    private static function scanDirectory(string $dir): array
    {
        // A declared-but-absent root must be skipped, not fatal: a project whose
        // PSR-4 root is not literally `src/` would otherwise abort `pulsar
        // optimize` with RecursiveDirectoryIterator "Failed to open directory".
        if (!is_dir($dir)) {
            return [];
        }

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
