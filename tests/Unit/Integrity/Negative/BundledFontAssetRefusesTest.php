<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\BundledFontAudit;
use Pulsar\Tests\Unit\Integrity\Support\FontFinding;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_map;
use function bin2hex;
use function copy;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_replace;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

/**
 * Plants each defect the bundled-font gate exists to refuse, and watches it refuse.
 *
 * The gate this covers was written because five font binaries had shipped unread, and the
 * lesson of that is not "check fonts" — it is that an artefact nobody has watched behave
 * is an artefact nobody has checked. A gate is an artefact. So the same treatment is
 * applied one level up: every rule here is given the defect it claims to catch.
 *
 * The trees are planted under the system temp root, never in the working tree, and their
 * removal is asserted rather than attempted — a fixture surviving the fixture's own test
 * is the failure this class argues against, in miniature.
 *
 * Coverage — whether a face can actually draw a digit — is not testable from PHP, because
 * reading it means decompressing Brotli. That half is watched failing in
 * tools/ci/verify-bundled-fonts.test.mjs, against the very file that shipped. What is
 * watched here is everything that keeps the two halves pointing at the same bytes.
 */
#[CoversNothing]
#[GuardsGate(
    gate: 'BundledFontAssetGateTest::everyBundledFontIsTheFileTheManifestVerified',
    plants: 'a font swapped for the latin-ext slice that actually shipped, a font whose '
        . 'recorded size no longer matches, a truncated file whose WOFF2 header outruns it, '
        . 'and an undeclared woff2 dropped into the font directory',
)]
#[GuardsGate(
    gate: 'BundledFontAssetGateTest::theStyleSheetAndTheManifestDescribeTheSameFaces',
    plants: 'a @font-face promising a weight range wider than the font varies over, a '
        . '@font-face serving a file the manifest does not describe, and a bundled face no '
        . 'rule in the style sheet uses',
)]
#[GuardsGate(
    gate: 'BundledFontAssetGateTest::everyFamilyShipsTheLicenceItIsRedistributedUnder',
    plants: 'a family whose OFL text is not bundled, and one whose bundled text has been edited',
)]
#[GuardsGate(
    gate: 'BundledFontAssetGateTest::theCoverageHalfOfThisGateStillRuns',
    plants: 'the coverage verifier deleted, and a vitest configuration that no longer '
        . 'collects the file it lives in',
)]
final class BundledFontAssetRefusesTest extends TestCase
{
    /** The code face as it actually shipped: 190 codepoints, no digits, no a-z. */
    private const string SHIPPED_SLICE = 'tests/Unit/Integrity/Fixture/fonts/jetbrains-mono-latin-ext-slice.woff2';

    private const string FONTS = 'resources/ui/fonts';

    private const string STYLE_SHEET = 'resources/ui/css/tokens.css';

    /** @var list<string> */
    private array $planted = [];

    protected function tearDown(): void
    {
        $trees = $this->planted;
        $this->planted = [];

        foreach ($trees as $tree) {
            self::removeTree($tree);
            self::assertDirectoryDoesNotExist($tree, 'a planted fixture tree survived the test');
        }
    }

    #[Test]
    public function itRefusesAFontSwappedForTheSliceThatShipped(): void
    {
        $tree = $this->plantTree();

        copy($this->root() . '/' . self::SHIPPED_SLICE, $tree . '/' . self::FONTS . '/jetbrains-mono-variable-latin.woff2');

        self::assertRefused(
            'bundle/sha256',
            $tree,
            'a code font with no digits, no a-z and no punctuation would have been swapped in '
            . 'under the name of the one that was verified',
        );
    }

    #[Test]
    public function itRefusesAFontWhoseRecordedSizeIsWrong(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/' . self::FONTS . '/montserrat-variable-latin.woff2';

        // One byte appended to a font nobody re-measured. Fonts are edited by tools, and a
        // tool that rewrites one and leaves the manifest alone is the ordinary way this
        // goes wrong — not a hand-typed number.
        file_put_contents($path, (string) file_get_contents($path) . "\x00");

        self::assertRefused(
            'bundle/bytes',
            $tree,
            'the repository would describe a font by a size it no longer has, and the coverage '
            . 'proof would be pinned to bytes that are no longer there',
        );
    }

    #[Test]
    public function itRefusesATruncatedFontFile(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/' . self::FONTS . '/overpass-variable-latin.woff2';
        $whole = (string) file_get_contents($path);

        file_put_contents($path, substr($whole, 0, 4096));

        self::assertRefused(
            'font/structure',
            $tree,
            'a file whose own header says it is longer than it is would have been served to '
            . 'browsers as a font',
        );
    }

    #[Test]
    public function itRefusesAWoff2NobodyDeclared(): void
    {
        $tree = $this->plantTree();

        copy($this->root() . '/' . self::SHIPPED_SLICE, $tree . '/' . self::FONTS . '/mystery-face.woff2');

        self::assertRefused(
            'bundle/undeclared-file',
            $tree,
            'a font would ship with nothing stating what it must cover and nothing having read it',
        );
    }

    #[Test]
    public function itRefusesAFontFaceThatPromisesWeightsTheFontDoesNotDraw(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/' . self::STYLE_SHEET;

        file_put_contents(
            $path,
            str_replace('font-weight: 100 800;', 'font-weight: 100 900;', (string) file_get_contents($path)),
        );

        self::assertRefused(
            'css/axis-range',
            $tree,
            'the browser would synthesise a weight nobody drew, and the design would render '
            . 'in something nobody chose',
        );
    }

    #[Test]
    public function itRefusesAStyleSheetServingAFontThatIsNotBundled(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/' . self::STYLE_SHEET;

        file_put_contents(
            $path,
            str_replace('overpass-variable-latin.woff2', 'overpass-v2-variable-latin.woff2', (string) file_get_contents($path)),
        );

        self::assertRefused(
            'css/unbundled-src',
            $tree,
            'every page would request a font that is not in the repository, and every reader '
            . 'would get the fallback',
        );
    }

    #[Test]
    public function itRefusesABundledFaceNoStyleSheetRuleUses(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/' . self::STYLE_SHEET;

        file_put_contents(
            $path,
            str_replace("  src: url('../fonts/overpass-italic-variable-latin.woff2') format('woff2');\n", '', (string) file_get_contents($path)),
        );

        self::assertRefused(
            'css/unreferenced-face',
            $tree,
            'a face would be carried in the repository, and in every distribution of it, '
            . 'without anything using it',
        );
    }

    #[Test]
    public function itRefusesAFamilyWhoseLicenceIsNotBundled(): void
    {
        $tree = $this->plantTree();

        unlink($tree . '/' . self::FONTS . '/OFL-Overpass.txt');

        self::assertRefused(
            'licence/missing',
            $tree,
            'the framework would redistribute an OFL font without the licence text the OFL '
            . 'requires to travel with it',
        );
    }

    #[Test]
    public function itRefusesALicenceTextThatHasBeenEdited(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/' . self::FONTS . '/OFL-Montserrat.txt';

        file_put_contents($path, str_replace('SIL OPEN FONT LICENSE Version 1.1', 'Some Other Licence', (string) file_get_contents($path)));

        self::assertRefusedAll(
            ['licence/sha256', 'licence/text'],
            $tree,
            'the terms shipped beside the font would no longer be the terms it is licensed under',
        );
    }

    #[Test]
    public function itRefusesTheCoverageVerifierBeingDeleted(): void
    {
        $tree = $this->plantTree();

        unlink($tree . '/tools/ci/verify-bundled-fonts.mjs');

        $findings = new BundledFontAudit($tree)->wiringFindings();

        self::assertContains(
            'wiring/verifier-missing',
            self::rules($findings),
            'the hashes in the manifest would go on passing while nothing checked what the '
            . 'hashed bytes can actually draw',
        );
    }

    #[Test]
    public function itRefusesAVitestConfigurationThatStopsCollectingTheGate(): void
    {
        $tree = $this->plantTree();
        $path = $tree . '/vitest.config.ts';

        file_put_contents($path, str_replace("'**/*.test.mjs'", "'**/*.test.never'", (string) file_get_contents($path)));

        $findings = new BundledFontAudit($tree)->wiringFindings();

        self::assertContains(
            'wiring/verifier-not-collected',
            self::rules($findings),
            'the coverage gate would still exist, still be committed, and never run again — '
            . 'which is the state nine gates in this repository were found in',
        );
    }

    /**
     * @param list<string> $expected
     */
    private static function assertRefusedAll(array $expected, string $tree, string $whatWouldHaveShipped): void
    {
        $findings = new BundledFontAudit($tree)->findings();
        $rules = self::rules($findings);

        foreach ($expected as $rule) {
            self::assertContains($rule, $rules, self::because($rule, $findings, $whatWouldHaveShipped));
        }
    }

    private static function assertRefused(string $rule, string $tree, string $whatWouldHaveShipped): void
    {
        self::assertRefusedAll([$rule], $tree, $whatWouldHaveShipped);
    }

    /**
     * @param list<FontFinding> $findings
     */
    private static function because(string $rule, array $findings, string $whatWouldHaveShipped): string
    {
        $reported = $findings === []
            ? '        (the audit reported nothing at all)'
            : '        ' . implode("\n        ", array_map(
                static fn(FontFinding $finding): string => $finding->describe(),
                $findings,
            ));

        return "The audit did not report '" . $rule . "' for a tree where it should have.\n"
            . 'Had the rule stayed silent, ' . $whatWouldHaveShipped . ".\n\n"
            . "What it did report:\n" . $reported;
    }

    /**
     * @param list<FontFinding> $findings
     *
     * @return list<string>
     */
    private static function rules(array $findings): array
    {
        return array_map(static fn(FontFinding $finding): string => $finding->rule, $findings);
    }

    /**
     * A copy of everything the audit reads, outside every path any gate scans.
     */
    private function plantTree(): string
    {
        $tree = sys_get_temp_dir() . '/pulsar-fonts-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($tree, 0o700, true), 'could not create a fixture tree at ' . $tree);

        $this->planted[] = $tree;

        foreach ([self::FONTS, 'resources/ui/css'] as $directory) {
            self::copyTree($this->root() . '/' . $directory, $tree . '/' . $directory);
        }

        // Only the two files the wiring rule looks for, rather than all of tools/ci: a
        // fixture that copies more than the rule reads invites the next reader to think
        // the rule reads it.
        self::assertTrue(mkdir($tree . '/tools/ci', 0o700, true), 'could not create the tools/ci fixture');

        foreach (['tools/ci/verify-bundled-fonts.mjs', 'tools/ci/verify-bundled-fonts.test.mjs', 'vitest.config.ts'] as $file) {
            self::assertTrue(copy($this->root() . '/' . $file, $tree . '/' . $file), 'could not copy ' . $file);
        }

        return $tree;
    }

    private static function copyTree(string $from, string $to): void
    {
        self::assertTrue(is_dir($from), 'nothing to copy at ' . $from);

        if (!is_dir($to)) {
            self::assertTrue(mkdir($to, 0o700, true), 'could not create ' . $to);
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $target = $to . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($from) + 1));

            if ($entry->isDir()) {
                if (!is_dir($target)) {
                    self::assertTrue(mkdir($target, 0o700, true), 'could not create ' . $target);
                }

                continue;
            }

            self::assertTrue(copy($entry->getPathname(), $target), 'could not copy ' . $entry->getPathname());
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
