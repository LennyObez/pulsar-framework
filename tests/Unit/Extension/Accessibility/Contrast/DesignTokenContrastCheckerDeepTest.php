<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Contrast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ColorParser;
use Pulsar\Extension\Accessibility\Contrast\DesignTokenContrastChecker;
use Pulsar\Extension\Accessibility\Contrast\LuminanceCalculator;
use RuntimeException;

#[CoversClass(DesignTokenContrastChecker::class)]
final class DesignTokenContrastCheckerDeepTest extends TestCase
{
    private DesignTokenContrastChecker $checker;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->checker = new DesignTokenContrastChecker(
            new ColorParser(),
            new LuminanceCalculator(),
        );
        $this->tmpDir = sys_get_temp_dir() . '/a11y_contrast_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function cssWithMatchingStandardPairsProducesResults(): void
    {
        $css = <<<'CSS'
            :root {
                --color-text: #333333;
                --color-bg: #ffffff;
            }
            CSS;

        $file = $this->tmpDir . '/tokens.css';
        file_put_contents($file, $css);

        $report = $this->checker->checkCssFile($file);

        self::assertSame(1, $report->totalPairs);
        self::assertTrue($report->results[0]->passesAaNormal);
    }

    #[Test]
    public function cssWithMultipleRootBlocks(): void
    {
        $css = <<<'CSS'
            :root {
                --color-text: #000000;
                --color-bg: #ffffff;
            }
            :root {
                --color-text-secondary: #666666;
            }
            CSS;

        $file = $this->tmpDir . '/multi-root.css';
        file_put_contents($file, $css);

        $report = $this->checker->checkCssFile($file);

        // --color-text/--color-bg and --color-text-secondary/--color-bg
        self::assertGreaterThanOrEqual(2, $report->totalPairs);
    }

    #[Test]
    public function cssWithCommentsStripped(): void
    {
        $css = <<<'CSS'
            /* This is a comment */
            :root {
                /* --color-text: #ff0000; */
                --color-text: #000000;
                --color-bg: #ffffff;
            }
            CSS;

        $file = $this->tmpDir . '/comments.css';
        file_put_contents($file, $css);

        $report = $this->checker->checkCssFile($file);

        self::assertSame(1, $report->totalPairs);
        // Should use #000000 not #ff0000 (which was in a comment)
        self::assertSame('#000000', $report->results[0]->foreground->toHex());
    }

    #[Test]
    public function cssWithVarReferencesSkipped(): void
    {
        $css = <<<'CSS'
            :root {
                --color-text: var(--dynamic);
                --color-bg: #ffffff;
                --color-text-secondary: #666666;
                --color-bg-secondary: var(--also-dynamic);
            }
            CSS;

        $file = $this->tmpDir . '/vars.css';
        file_put_contents($file, $css);

        $report = $this->checker->checkCssFile($file);

        // Pairs involving var() are skipped, but --color-text-secondary/#666666
        // paired with --color-bg/#ffffff is a valid non-var() pair
        self::assertSame(1, $report->totalPairs);
    }

    #[Test]
    public function cssWithNoRootBlock(): void
    {
        $css = 'body { color: #333; }';

        $file = $this->tmpDir . '/no-root.css';
        file_put_contents($file, $css);

        $report = $this->checker->checkCssFile($file);

        self::assertSame(0, $report->totalPairs);
    }

    #[Test]
    public function checkTokenPairsWithMultiplePairs(): void
    {
        $pairs = [
            ['fg' => '#000000', 'bg' => '#ffffff', 'fgToken' => '--a', 'bgToken' => '--b'],
            ['fg' => '#ffffff', 'bg' => '#eeeeee', 'fgToken' => '--c', 'bgToken' => '--d'],
        ];

        $report = $this->checker->checkTokenPairs($pairs);

        self::assertSame(2, $report->totalPairs);
        self::assertEqualsWithDelta(21.0, $report->results[0]->ratio, 0.5);
    }

    #[Test]
    public function checkTokenPairsEmpty(): void
    {
        $report = $this->checker->checkTokenPairs([]);

        self::assertSame(0, $report->totalPairs);
    }

    #[Test]
    public function nonReadableFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->checker->checkCssFile($this->tmpDir . '/does-not-exist.css');
    }

    #[Test]
    public function cssWithAllStandardPairs(): void
    {
        $css = <<<'CSS'
            :root {
                --color-text: #222222;
                --color-text-secondary: #555555;
                --color-text-muted: #888888;
                --color-link: #0066cc;
                --color-bg: #ffffff;
                --color-bg-secondary: #f5f5f5;
                --color-bg-tertiary: #eeeeee;
                --color-bg-elevated: #fafafa;
            }
            CSS;

        $file = $this->tmpDir . '/full-tokens.css';
        file_put_contents($file, $css);

        $report = $this->checker->checkCssFile($file);

        // 4 text tokens x 4 bg tokens = 16 pairs
        self::assertSame(16, $report->totalPairs);
    }
}
