<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff;
use function array_keys;
use function array_values;
use function dirname;
use function file_get_contents;
use function implode;
use function is_dir;
use function preg_match_all;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * Every command the CLI reference documents must be a command that exists.
 *
 * `docs/cli-reference.md` carried a full section for `scaffold:module` —
 * arguments, options, the directory tree it produced — for a command no release
 * has ever registered. A reader following the reference met "Unknown command",
 * and nothing in the suite could tell them apart from the sections that work.
 *
 * The check is deliberately name-level rather than behavioural. It cannot say
 * whether the documented options are right, but it can say the command is real,
 * and that is exactly the class of rot that produced the invented section.
 *
 * Availability is a separate question this does not test: a command whose
 * subsystem ships disabled is real but absent from `pulsar list` until the
 * operator enables it. The reference marks those cases; see "Why `pulsar list`
 * is shorter than this page".
 */
#[CoversNothing]
final class CliReferenceCommandsExistTest extends TestCase
{
    #[Test]
    public function everyDocumentedCommandIsRegisteredSomewhereInTheTree(): void
    {
        $documented = $this->documentedCommandNames();
        self::assertNotSame([], $documented, 'The CLI reference documents no commands; the heading format changed');

        $declared = $this->declaredCommandNames();
        self::assertNotSame([], $declared, 'No command names were found in the source tree; the scan is broken');

        $invented = array_values(array_diff($documented, $declared));

        self::assertSame(
            [],
            $invented,
            'docs/cli-reference.md documents commands that do not exist: ' . implode(', ', $invented),
        );
    }

    /**
     * Command names under a `#### \`name\`` heading in the reference.
     *
     * @return list<string>
     */
    private function documentedCommandNames(): array
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'cli-reference.md';
        self::assertFileExists($path);

        $markdown = file_get_contents($path);
        self::assertIsString($markdown);

        preg_match_all('/^#### `([a-z0-9][a-z0-9:_-]*)`/m', $markdown, $matches);

        $names = $matches[1];
        sort($names);

        return $names;
    }

    /**
     * Every name a console command assigns itself, across src/ and extensions/.
     *
     * Read from the source rather than from a booted application on purpose: a
     * booted CLI only registers the commands whose subsystem is enabled, so
     * comparing against it would flag `queue:work` and a dozen others as
     * non-existent when they are merely switched off.
     *
     * @return list<string>
     */
    private function declaredCommandNames(): array
    {
        $root = dirname(__DIR__, 3);
        $names = [];

        foreach (['src', 'extensions'] as $tree) {
            $directory = $root . DIRECTORY_SEPARATOR . $tree;

            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if ($source === false) {
                    continue;
                }

                preg_match_all('/\$this->name\s*=\s*\'([a-z0-9][a-z0-9:_-]*)\'/', $source, $matches);

                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        $declared = array_keys($names);
        sort($declared);

        return $declared;
    }
}
