<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function class_exists;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Every class a PSR-4 tree declares, reflected.
 *
 * The attribute ratchets — #[NoDiscard] and #[\Override] — both answer their questions
 * through reflection, and both hard-wired the tree to `src`. That is what made them
 * unwatchable: reflection over `src` can only ever describe code that already satisfies
 * them, so their silence carried no information. Naming the tree and its namespace makes
 * the same collection runnable over a fixture tree that does not satisfy them.
 */
final readonly class ReflectedClassIndex
{
    /**
     * @param string $directory       The PSR-4 root to walk
     * @param string $namespacePrefix What that root maps to, trailing separator included
     */
    public function __construct(
        private string $directory,
        private string $namespacePrefix,
    ) {}

    /**
     * @return list<ReflectionClass<object>>
     */
    public function classes(): array
    {
        $classes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->classNameFor($file->getPathname());

            if ($className === null) {
                continue;
            }

            $classes[] = new ReflectionClass($className);
        }

        return $classes;
    }

    /**
     * @return class-string|null
     */
    private function classNameFor(string $path): ?string
    {
        $relative = substr($path, strlen($this->directory) + 1);
        $relative = str_replace('.php', '', $relative);
        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        $relative = str_replace('/', '\\', $relative);

        $fqcn = $this->namespacePrefix . $relative;

        return class_exists($fqcn) ? $fqcn : null;
    }
}
