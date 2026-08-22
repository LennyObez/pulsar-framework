<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function is_file;
use function ksort;
use function realpath;
use function sort;
use function str_contains;
use function str_replace;

/**
 * Keeps Infection's PHPUnit configuration in step with the shared one.
 *
 * tools/php/mutation/phpunit.xml exists because Infection's initial run cannot
 * hold the whole Unit suite in memory. It is a copy of tools/php/phpunit.xml with
 * different suites and a narrower source scope, and a copy is exactly the kind of
 * thing that rots quietly: a strictness flag added to the shared file and not to
 * this one would leave mutation testing running under looser rules than the suite
 * it claims to measure, and nothing would say so.
 *
 * So the two are compared here on everything they are supposed to share.
 */
final class MutationConfigTest extends TestCase
{
    private const string SHARED = __DIR__ . '/../../../tools/php/phpunit.xml';
    private const string MUTATION = __DIR__ . '/../../../tools/php/mutation/phpunit.xml';

    #[Test]
    public function bothConfigurationsExist(): void
    {
        self::assertTrue(is_file(self::SHARED));
        self::assertTrue(is_file(self::MUTATION));
    }

    #[Test]
    public function theStrictnessSettingsAreIdentical(): void
    {
        $shared = $this->rootAttributes(self::SHARED);
        $mutation = $this->rootAttributes(self::MUTATION);

        // bootstrap and cacheDirectory differ by design: the files sit at different
        // depths, so the same target needs a different relative path.
        unset($shared['bootstrap'], $shared['cacheDirectory']);
        unset($mutation['bootstrap'], $mutation['cacheDirectory']);

        self::assertSame(
            $shared,
            $mutation,
            'a strictness flag set on one configuration and not the other would let '
            . 'mutation testing run under looser rules than the suite it measures',
        );
    }

    #[Test]
    public function theSameRunnerExtensionsAreRegistered(): void
    {
        self::assertSame(
            $this->extensionClasses(self::SHARED),
            $this->extensionClasses(self::MUTATION),
        );
    }

    #[Test]
    public function theSamePhpBlockIsDeclared(): void
    {
        self::assertSame(
            $this->phpDirectives(self::SHARED),
            $this->phpDirectives(self::MUTATION),
        );
    }

    #[Test]
    public function bothBootstrapTheSameFile(): void
    {
        // Different relative paths, one target. Resolved rather than compared as
        // strings, because "../bootstrap.php" and "bootstrap.php" are the same file
        // seen from two directories.
        self::assertSame(
            realpath(dirname(self::SHARED) . '/' . $this->rootAttributes(self::SHARED)['bootstrap']),
            realpath(dirname(self::MUTATION) . '/' . $this->rootAttributes(self::MUTATION)['bootstrap']),
        );
    }

    #[Test]
    public function theMutationSourceScopeMatchesWhatInfectionMutates(): void
    {
        $configured = $this->sourceDirectories(self::MUTATION);
        sort($configured);

        self::assertSame(
            ['src/Audit', 'src/Auth', 'src/Security'],
            $configured,
            'collecting coverage for source Infection never mutates costs memory for nothing',
        );

        $infection = (string) file_get_contents(__DIR__ . '/../../../infection.json5');

        foreach ($configured as $directory) {
            self::assertTrue(
                str_contains($infection, "'" . $directory . "'"),
                $directory . ' is measured here but absent from infection.json5',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function rootAttributes(string $path): array
    {
        $root = $this->load($path)->documentElement;
        self::assertInstanceOf(DOMElement::class, $root);

        $attributes = [];

        foreach ($root->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        ksort($attributes);

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function extensionClasses(string $path): array
    {
        $classes = [];

        foreach ($this->load($path)->getElementsByTagName('bootstrap') as $element) {
            $classes[] = $element->getAttribute('class');
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function phpDirectives(string $path): array
    {
        $directives = [];
        $document = $this->load($path);

        foreach (['ini', 'const', 'env', 'server'] as $kind) {
            foreach ($document->getElementsByTagName($kind) as $element) {
                $directives[] = $kind . ':' . $element->getAttribute('name') . '=' . $element->getAttribute('value');
            }
        }

        sort($directives);

        return $directives;
    }

    /**
     * @return list<string>
     */
    private function sourceDirectories(string $path): array
    {
        $document = $this->load($path);
        $sources = $document->getElementsByTagName('source');
        self::assertGreaterThan(0, $sources->length, 'the configuration declares no source scope');

        $directories = [];

        $source = $sources->item(0);
        self::assertInstanceOf(DOMElement::class, $source);

        foreach ($source->getElementsByTagName('directory') as $element) {
            $directories[] = str_replace('../../../', '', $element->textContent);
        }

        return $directories;
    }

    private function load(string $path): DOMDocument
    {
        $document = new DOMDocument();
        self::assertTrue($document->load($path), $path . ' is not readable XML');

        return $document;
    }
}
