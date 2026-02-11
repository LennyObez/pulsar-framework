<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Contrast;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ColorParser;
use Pulsar\Extension\Accessibility\Contrast\DesignTokenContrastChecker;
use Pulsar\Extension\Accessibility\Contrast\LuminanceCalculator;
use RuntimeException;

use function dirname;

final class DesignTokenContrastCheckerTest extends TestCase
{
    private DesignTokenContrastChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new DesignTokenContrastChecker(
            new ColorParser(),
            new LuminanceCalculator(),
        );
    }

    #[Test]
    public function check_real_default_css_produces_report(): void
    {
        $cssPath = dirname(__DIR__, 5) . '/resources/themes/default.css';

        if (!is_file($cssPath)) {
            self::markTestSkipped('default.css not available');
        }

        $report = $this->checker->checkCssFile($cssPath);

        self::assertGreaterThanOrEqual(0, $report->totalPairs);
    }

    #[Test]
    public function check_custom_token_pairs(): void
    {
        $pairs = [
            ['fg' => '#000000', 'bg' => '#ffffff', 'fgToken' => '--text', 'bgToken' => '--bg'],
            ['fg' => '#767676', 'bg' => '#ffffff', 'fgToken' => '--muted', 'bgToken' => '--bg'],
        ];

        $report = $this->checker->checkTokenPairs($pairs);

        self::assertSame(2, $report->totalPairs);
        self::assertGreaterThanOrEqual(1, $report->passingAa);
    }

    #[Test]
    public function skip_var_references_gracefully(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y_css_');
        self::assertNotFalse($tmpFile);

        file_put_contents($tmpFile, <<<'CSS'
            :root {
                --color-text: var(--dynamic-text);
                --color-bg: #ffffff;
            }
            CSS);

        try {
            $report = $this->checker->checkCssFile($tmpFile);
            // var() references should be skipped, so no results for that pair
            self::assertSame(0, $report->totalPairs);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function non_existent_file_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);

        $this->checker->checkCssFile('/nonexistent/path/to/file.css');
    }

    #[Test]
    public function css_with_no_color_tokens_produces_empty_report(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'a11y_css_');
        self::assertNotFalse($tmpFile);

        file_put_contents($tmpFile, ':root { --font-size: 16px; }');

        try {
            $report = $this->checker->checkCssFile($tmpFile);
            self::assertSame(0, $report->totalPairs);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function contrast_result_fields_are_populated(): void
    {
        $pairs = [
            ['fg' => '#000000', 'bg' => '#ffffff', 'fgToken' => '--fg', 'bgToken' => '--bg'],
        ];

        $report = $this->checker->checkTokenPairs($pairs);

        self::assertCount(1, $report->results);

        $result = $report->results[0];
        self::assertSame('--fg', $result->foregroundToken);
        self::assertSame('--bg', $result->backgroundToken);
        self::assertEqualsWithDelta(21.0, $result->ratio, 0.1);
        self::assertTrue($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertTrue($result->passesAaaNormal);
        self::assertTrue($result->passesAaaLarge);
    }
}
