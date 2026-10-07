<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\BundledFontAudit;
use Pulsar\Tests\Unit\Integrity\Support\FontFinding;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function str_starts_with;

/**
 * The fonts this framework ships have to be the fonts this framework verified.
 *
 * `resources/ui/fonts/jetbrains-mono-variable.woff2` was a latin-ext subset slice for as
 * long as it had existed: 15 KB, 190 codepoints, no digits, no a-z, no punctuation. It is
 * the file `--font-mono` resolves to, so every code block, CLI transcript and monospace
 * figure the framework rendered — Studio, the error pages, the documentation, the front
 * office — silently fell back to Consolas. Both Overpass files had the same defect one
 * step quieter: the latin slice, 232 codepoints, so the Latin Extended-A and Cyrillic
 * characters that the shipped cs, pl, hu, lt, lv, mt, ro, sk, sl, hr, et and bg
 * translations are written in fell back too, in the body face, on every page.
 *
 * Nothing failed, because nothing had ever opened the files. Five binaries sat in the tree
 * being trusted for the same reason prose gets trusted: they had no failure mode anybody
 * could see.
 *
 * This is the half of the repair PHP can hold. Reading what a font actually covers means
 * decompressing Brotli, which this toolchain's PHP cannot do, so
 * tools/ci/verify-bundled-fonts.mjs does that under `pnpm test` and this pins the bytes it
 * verified. The two halves are joined by SHA-256: swap a font and the hash fails here;
 * rewrite the manifest to match a font nobody read and the coverage fails there. The
 * fourth rule below keeps that arrangement honest by refusing to let the other half
 * quietly stop running.
 */
#[CoversNothing]
final class BundledFontAssetGateTest extends TestCase
{
    #[Test]
    public function everyBundledFontIsTheFileTheManifestVerified(): void
    {
        $this->assertNoFindings('bundle/', 'font/');
    }

    #[Test]
    public function theStyleSheetAndTheManifestDescribeTheSameFaces(): void
    {
        $this->assertNoFindings('css/');
    }

    #[Test]
    public function everyFamilyShipsTheLicenceItIsRedistributedUnder(): void
    {
        $this->assertNoFindings('licence/');
    }

    #[Test]
    public function theCoverageHalfOfThisGateStillRuns(): void
    {
        $findings = new BundledFontAudit($this->root())->wiringFindings();

        self::assertSame(
            [],
            self::describe($findings),
            "The hashes this file pins are only worth what the coverage check behind them is\n"
            . "worth. With that check gone or uncollected, every assertion here still passes\n"
            . 'over a manifest nobody verifies any more.',
        );
    }

    /**
     * @param string ...$prefixes rule families this assertion is responsible for
     */
    private function assertNoFindings(string ...$prefixes): void
    {
        $findings = new BundledFontAudit($this->root())->findings();

        $relevant = array_values(array_filter(
            $findings,
            static function (FontFinding $finding) use ($prefixes): bool {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($finding->rule, $prefix)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        self::assertSame(
            [],
            self::describe($relevant),
            "The bundled fonts are not what the repository says they are. A face that is not\n"
            . "the file somebody verified is a face nobody has verified — which is how a code\n"
            . "font with no digits in it shipped for months behind a green build.\n\n"
            . 'Rebuild the bundle with scripts/build-fonts.sh, which writes the manifest from '
            . 'the files it produces, and run `node tools/ci/verify-bundled-fonts.mjs`.',
        );
    }

    /**
     * @param list<FontFinding> $findings
     *
     * @return list<string>
     */
    private static function describe(array $findings): array
    {
        return array_map(static fn(FontFinding $finding): string => $finding->describe(), $findings);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
