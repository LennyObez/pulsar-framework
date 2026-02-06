<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use function class_exists;

use const DIRECTORY_SEPARATOR;

use function enum_exists;
use function file_get_contents;
use function file_put_contents;

use FilesystemIterator;

use function is_array;
use function is_file;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use JsonException;

use const LOCK_EX;

use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use Serializable;

use function sort;

use SplFileInfo;

use function str_starts_with;

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
     */
    public static function scan(string $vendorPath, string $srcPath): array
    {
        $candidates = self::discoverCandidates($vendorPath, $srcPath);
        $allowed = [];

        foreach ($candidates as $className) {
            if (self::isEligible($className)) {
                $allowed[] = $className;
            }
        }

        sort($allowed);

        return $allowed;
    }

    /**
     * Load the saved allowlist from the cache directory.
     *
     * @return list<class-string>|null Null if not found or invalid.
     */
    public static function load(string $cachePath): ?array
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var list<class-string> $decoded */
        return $decoded;
    }

    /**
     * Save the allowlist to disk as JSON.
     *
     * @param list<class-string> $classes
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
        $eligible = [];

        foreach ($classNames as $className) {
            foreach (self::ELIGIBLE_NAMESPACES as $namespace) {
                if (str_starts_with($className, $namespace)) {
                    /** @var class-string $className */
                    $eligible[] = $className;
                    break;
                }
            }
        }

        return $eligible;
    }

    /**
     * Check if a class is eligible for cache deserialization.
     *
     * Eligibility criteria:
     * 1. Class is either a readonly class or a backed enum
     * 2. Class does NOT implement Serializable
     * 3. Class does NOT define any dangerous magic methods
     */
    private static function isEligible(string $className): bool
    {
        if (!class_exists($className) && !enum_exists($className)) {
            return false;
        }

        // Check if it's a backed enum
        if (enum_exists($className)) {
            try {
                $ref = new ReflectionEnum($className);

                return $ref->isBacked() && !self::hasDangerousMethods($ref);
            } catch (ReflectionException) {
                return false;
            }
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
        foreach (self::DANGEROUS_METHODS as $method) {
            if ($ref->hasMethod($method)) {
                $m = $ref->getMethod($method);
                // Only count it if declared on this class (not inherited from a parent)
                if ($m->getDeclaringClass()->getName() === $ref->getName()) {
                    return true;
                }
            }
        }

        return false;
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
