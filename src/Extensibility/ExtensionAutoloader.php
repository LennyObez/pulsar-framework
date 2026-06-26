<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Internal;

use function is_file;
use function spl_autoload_register;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function uksort;

use const DIRECTORY_SEPARATOR;

/**
 * PSR-4 autoloader for extension namespaces.
 *
 * Extensions are no longer baked into the framework's root composer.json
 * autoload (ADR-0004: "no privileged built-in access"). Instead, their
 * classes are resolved through this autoloader, which is registered when
 * extensions are discovered — at HTTP boot (via {@see ExtensionBootstrap}),
 * on the CLI (bin/pulsar), and in the test bootstrap.
 *
 * Prefixes are matched longest-first so a nested namespace mapped to a
 * different directory than its parent resolves correctly.
 *
 * The instance is kept alive for the process lifetime by the SPL autoload
 * registry once {@see self::register()} is called, so no global/static state
 * is required.
 */
#[Internal]
final class ExtensionAutoloader
{
    /**
     * Namespace prefix (with trailing separator) => absolute base directory.
     * Kept sorted by descending prefix length for longest-match resolution.
     *
     * @var array<string, string>
     */
    private array $prefixes = [];

    /**
     * Add PSR-4 prefix → directory mappings.
     *
     * @param array<string, string> $psr4 Namespace prefix (trailing `\`) => absolute base dir
     */
    public function addPsr4(array $psr4): void
    {
        foreach ($psr4 as $prefix => $directory) {
            $this->prefixes[$prefix] = $directory;
        }

        // Longest prefix first: a class under a deeper namespace mapped to its
        // own directory must win over a shorter ancestor prefix.
        uksort($this->prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * Resolve and load a class. Returns true when this autoloader handled it.
     *
     * Safe to register alongside Composer's autoloader: returns false (without
     * error) for any class outside the registered extension namespaces, letting
     * the next registered autoloader try.
     */
    public function loadClass(string $class): bool
    {
        foreach ($this->prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $directory . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return true;
            }
        }

        return false;
    }

    /**
     * Register this autoloader with the SPL autoload stack.
     */
    public function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $this->loadClass($class);
        });
    }

    /**
     * Discover extensions under the given paths and register an autoloader for
     * every discovered manifest's namespace(s).
     *
     * Used by entry points that reference extension classes outside the Kernel
     * boot flow (the CLI and the test bootstrap). The returned instance stays
     * alive via the SPL registry; callers may discard it.
     *
     * @param list<string> $paths Directories to scan for extension manifests
     */
    public static function registerForPaths(array $paths): self
    {
        $autoloader = new self();
        $manifests = new ExtensionLoader()->discover($paths);

        foreach ($manifests as $manifest) {
            $autoloader->addPsr4($manifest->autoloadMap());
        }

        $autoloader->register();

        return $autoloader;
    }
}
