<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function dirname;
use function file_get_contents;
use function implode;
use function json_decode;
use function preg_match;
use function preg_split;
use function sort;
use function str_starts_with;
use function substr;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PREG_SPLIT_NO_EMPTY;

/**
 * The first page a newcomer reads must not disagree with the manifest.
 *
 * `docs/getting-started.md` told readers PHP 8.5 was enough while `composer.json`
 * required `>=8.5.1`, and named four extensions where the manifest required
 * fourteen. Both errors surface at the same moment and in the same way: Composer
 * refuses to install, having been told the environment was fine.
 *
 * A prose promise about a machine-readable file is only as good as a check that
 * reads both. This is that check.
 */
#[CoversNothing]
final class GettingStartedRequirementsTest extends TestCase
{
    #[Test]
    public function theDocumentedPhpVersionIsTheOneComposerRequires(): void
    {
        /** @var mixed $constraint */
        $constraint = $this->composerRequire()['php'] ?? null;
        self::assertIsString($constraint, 'composer.json must require a PHP version');

        if (preg_match('/(\d+\.\d+\.\d+)/', $constraint, $matches) !== 1) {
            self::fail("The PHP constraint '{$constraint}' has no three-part version to compare against");
        }

        self::assertStringContainsString(
            $matches[1],
            $this->guide(),
            "The getting-started guide does not name PHP {$matches[1]}, which composer.json requires",
        );
    }

    /**
     * The guide's extension block must be the manifest's `ext-*` set exactly.
     *
     * Set equality in both directions on purpose. Listing too few is what sent
     * readers into a failing `composer install`; listing too many would send them
     * installing packages nothing needs, and would rot just as quietly.
     */
    #[Test]
    public function theDocumentedExtensionSetIsTheOneComposerRequires(): void
    {
        $required = [];

        foreach (array_keys($this->composerRequire()) as $package) {
            if (str_starts_with($package, 'ext-')) {
                $required[] = substr($package, 4);
            }
        }

        sort($required);
        self::assertNotSame([], $required, 'composer.json requires no extensions; the parse is wrong');

        $documented = $this->documentedExtensions();

        self::assertSame(
            $required,
            $documented,
            "The getting-started guide's extension list has drifted from composer.json.\n"
            . 'composer.json: ' . implode(' ', $required) . "\n"
            . 'guide:         ' . implode(' ', $documented),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function composerRequire(): array
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'composer.json';
        self::assertFileExists($path);

        $json = file_get_contents($path);
        self::assertIsString($json);

        /** @var mixed $manifest */
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        /** @var mixed $require */
        $require = $manifest['require'] ?? null;
        self::assertIsArray($require);

        /** @var array<string, mixed> $require */
        return $require;
    }

    private function guide(): string
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'getting-started.md';
        self::assertFileExists($path);

        $markdown = file_get_contents($path);
        self::assertIsString($markdown);

        return $markdown;
    }

    /**
     * The extension names in the guide's requirements block.
     *
     * Anchored on the sentence that introduces it rather than on "the first
     * fenced block", so an added paragraph above cannot silently point the check
     * at a different listing.
     *
     * @return list<string>
     */
    private function documentedExtensions(): array
    {
        if (preg_match('/is what it requires today:\s*\n```\n(.+?)\n```/s', $this->guide(), $matches) !== 1) {
            self::fail('The getting-started guide has no extension requirements block to compare');
        }

        $names = preg_split('/\s+/', $matches[1], -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($names);

        sort($names);

        return $names;
    }
}
