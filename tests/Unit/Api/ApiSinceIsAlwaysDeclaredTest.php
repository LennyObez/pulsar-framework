<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function chr;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function is_array;
use function is_dir;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function token_get_all;
use function trim;

use const T_ATTRIBUTE;

/**
 * Every `#[Api]` in the tree names the version it became public in.
 *
 * `since` is what the attribute is for. It is the only field carried into
 * `tools/api/public-api.snapshot.json`, and it is the answer to the only question
 * a consumer asks of a stability marker: from which release may I depend on this?
 *
 * `Pulsar\Security\AntiSpam\AntiSpamConfigSet` was marked `#[Api]` with no
 * arguments. The attribute's `since` defaulted to `''`, the snapshot recorded
 * `"since": ""`, every gate that reads the snapshot accepted it, and the type sat
 * on the public surface for a whole RC cycle pledging stability as of no release.
 * Nothing was looking, which is the situation ADR-0060 exists to name.
 *
 * Two things now look. The attribute's `since` parameter lost its default, so
 * `#[Api]` bare is rejected by PHPStan, by Psalm and by reflection. That cannot
 * catch `#[Api(since: '')]`, which is still a well-formed call, so this test reads
 * the argument and rejects an empty or blank one — over the source text, because
 * the point is to see what an author wrote rather than what a default filled in.
 *
 * @see \Pulsar\Api\Api
 * @see \Pulsar\Tests\Unit\Api\PublicApiSnapshotTest
 */
#[CoversNothing]
final class ApiSinceIsAlwaysDeclaredTest extends TestCase
{
    /**
     * Lower bound on the files the scan must reach.
     *
     * Without it, a moved source root or a broken iterator turns this guard into
     * a test that passes because it looked at nothing.
     */
    private const int MINIMUM_FILES_SCANNED = 2000;

    /**
     * Lower bound on the `#[Api]` attributes the scan must find. The tree holds
     * more than three thousand; a scan that finds a handful has stopped
     * recognising the attribute rather than found the surface shrinking.
     */
    private const int MINIMUM_ATTRIBUTES_FOUND = 2500;

    #[Test]
    public function everyApiAttributeNamesTheVersionItBecamePublicIn(): void
    {
        [$offenders, $scanned, $found] = $this->scan();

        self::assertGreaterThan(
            self::MINIMUM_FILES_SCANNED,
            $scanned,
            'the scan found almost no PHP files, so its empty result proves nothing',
        );

        self::assertGreaterThan(
            self::MINIMUM_ATTRIBUTES_FOUND,
            $found,
            'the scan found almost no #[Api] attributes, so its empty result proves nothing',
        );

        self::assertSame(
            [],
            $offenders,
            "An #[Api] attribute does not name a version.\n\n"
            . "Write the release the type became public in, e.g. #[Api(since: '1.0.0-rc.12')]. "
            . 'It is the field the public API snapshot carries and the only thing that tells a '
            . "consumer from which release the type may be depended on.\n\nOffenders:\n- "
            . implode("\n- ", $offenders),
        );
    }

    /**
     * Scan `src/` and `extensions/` for `#[Api]` attributes without a usable
     * `since` argument.
     *
     * @return array{list<string>, int, int} Offenders, files scanned, attributes found
     */
    private function scan(): array
    {
        $root = str_replace(chr(92), '/', dirname(__DIR__, 3));
        $offenders = [];
        $scanned = 0;
        $found = 0;

        foreach (['src', 'extensions'] as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $scanned++;
                $relative = substr(str_replace(chr(92), '/', $file->getPathname()), strlen($root) + 1);
                $source = file_get_contents($file->getPathname());

                if ($source === false || !str_contains($source, '#[Api')) {
                    continue;
                }

                foreach ($this->apiAttributesIn($source) as $line => $text) {
                    $found++;

                    if ($this->declaresASince($text)) {
                        continue;
                    }

                    $offenders[] = $relative . ':' . $line . '  #[' . $text . ']';
                }
            }
        }

        return [$offenders, $scanned, $found];
    }

    /**
     * The `#[Api...]` attribute texts in one file, keyed by line number.
     *
     * Attributes are read from the token stream rather than by regular
     * expression so that the word "Api" in a docblock — and half the files here
     * discuss the attribute in prose — cannot be mistaken for a use of it.
     *
     * @return array<int, string>
     */
    private function apiAttributesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $attributes = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_ATTRIBUTE) {
                continue;
            }

            $line = $token[2];
            $depth = 1;
            $text = '';

            for ($j = $i + 1; $j < $count && $depth > 0; $j++) {
                $piece = $tokens[$j];
                $literal = is_array($piece) ? $piece[1] : $piece;

                if ($literal === '[' || $literal === '(') {
                    $depth++;
                } elseif ($literal === ']' || $literal === ')') {
                    $depth--;
                }

                if ($depth > 0) {
                    $text .= $literal;
                }
            }

            foreach ($this->splitAttributeGroup($text) as $single) {
                if ($this->isTheApiAttribute($single)) {
                    $attributes[$line] = $single;
                }
            }
        }

        return $attributes;
    }

    /**
     * Split `#[One(a, b), Two]` into its member attributes.
     *
     * Only top-level commas separate members; a comma inside an argument list
     * belongs to that member.
     *
     * @return list<string>
     */
    private function splitAttributeGroup(string $text): array
    {
        $members = [];
        $current = '';
        $depth = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $character = $text[$i];

            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $members[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $members[] = trim($current);

        return $members;
    }

    /**
     * Whether one attribute member is Pulsar's `#[Api]`, written short or fully
     * qualified, and not some other attribute whose name merely starts with it.
     */
    private function isTheApiAttribute(string $attribute): bool
    {
        $name = ltrim(trim($attribute), chr(92));
        $name = rtrim($name);

        foreach (['Api', 'Pulsar' . chr(92) . 'Api' . chr(92) . 'Api'] as $candidate) {
            if ($name === $candidate || str_starts_with($name, $candidate . '(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the attribute passes a non-blank `since`.
     *
     * Only the named form is accepted, because that is the only form the tree
     * uses and a positional first argument would read as a version by accident.
     */
    private function declaresASince(string $attribute): bool
    {
        if (preg_match('/since\s*:\s*(\x27|")([^\x27"]*)\1/', $attribute, $matches) !== 1) {
            return false;
        }

        return trim($matches[2]) !== '';
    }
}
