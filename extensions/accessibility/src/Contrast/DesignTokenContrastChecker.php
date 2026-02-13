<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Checks WCAG contrast ratios for CSS design token color pairs.
 *
 * Extracts --color-* custom properties from :root blocks and evaluates
 * standard text/background combinations against WCAG 2.1 thresholds.
 */
#[Api(since: '1.0.0')]
final readonly class DesignTokenContrastChecker
{
    /** @var list<array{text: string, bg: string}> */
    private const array STANDARD_PAIRS = [
        ['text' => '--color-text', 'bg' => '--color-bg'],
        ['text' => '--color-text', 'bg' => '--color-bg-secondary'],
        ['text' => '--color-text', 'bg' => '--color-bg-tertiary'],
        ['text' => '--color-text', 'bg' => '--color-bg-elevated'],
        ['text' => '--color-text-secondary', 'bg' => '--color-bg'],
        ['text' => '--color-text-secondary', 'bg' => '--color-bg-secondary'],
        ['text' => '--color-text-secondary', 'bg' => '--color-bg-tertiary'],
        ['text' => '--color-text-secondary', 'bg' => '--color-bg-elevated'],
        ['text' => '--color-text-muted', 'bg' => '--color-bg'],
        ['text' => '--color-text-muted', 'bg' => '--color-bg-secondary'],
        ['text' => '--color-text-muted', 'bg' => '--color-bg-tertiary'],
        ['text' => '--color-text-muted', 'bg' => '--color-bg-elevated'],
        ['text' => '--color-link', 'bg' => '--color-bg'],
        ['text' => '--color-link', 'bg' => '--color-bg-secondary'],
        ['text' => '--color-link', 'bg' => '--color-bg-tertiary'],
        ['text' => '--color-link', 'bg' => '--color-bg-elevated'],
    ];

    public function __construct(
        private ColorParser $colorParser,
        private LuminanceCalculator $luminance,
    ) {}

    /**
     * Reads a CSS file, extracts --color-* custom property definitions
     * from :root blocks, and evaluates standard text/background combinations.
     */
    public function checkCssFile(string $cssFilePath): ContrastReport
    {
        if (!is_file($cssFilePath) || !is_readable($cssFilePath)) {
            throw new RuntimeException(sprintf(
                'CSS file not found or not readable: "%s"',
                $cssFilePath,
            ));
        }

        $css = file_get_contents($cssFilePath);

        if ($css === false) {
            throw new RuntimeException(sprintf(
                'Failed to read CSS file: "%s"',
                $cssFilePath,
            ));
        }

        $tokens = $this->extractColorTokens($css);
        $results = [];

        foreach (self::STANDARD_PAIRS as $pair) {
            $textToken = $pair['text'];
            $bgToken = $pair['bg'];

            if (!isset($tokens[$textToken]) || !isset($tokens[$bgToken])) {
                continue;
            }

            $textValue = $tokens[$textToken];
            $bgValue = $tokens[$bgToken];

            // Skip var() references — cannot resolve statically
            if ($this->containsVarReference($textValue) || $this->containsVarReference($bgValue)) {
                continue;
            }

            $fg = $this->colorParser->parse($textValue);
            $bg = $this->colorParser->parse($bgValue);
            $ratio = $this->luminance->contrastRatio($fg, $bg);

            $results[] = new ContrastResult($fg, $bg, $textToken, $bgToken, $ratio);
        }

        return new ContrastReport($results);
    }

    /**
     * Checks explicit foreground/background pairs for contrast compliance.
     *
     * @param list<array{fg: string, bg: string, fgToken: string, bgToken: string}> $pairs
     */
    public function checkTokenPairs(array $pairs): ContrastReport
    {
        $results = [];

        foreach ($pairs as $pair) {
            $fg = $this->colorParser->parse($pair['fg']);
            $bg = $this->colorParser->parse($pair['bg']);
            $ratio = $this->luminance->contrastRatio($fg, $bg);

            $results[] = new ContrastResult($fg, $bg, $pair['fgToken'], $pair['bgToken'], $ratio);
        }

        return new ContrastReport($results);
    }

    /**
     * Extracts --color-* custom properties from :root { ... } blocks.
     *
     * @return array<string, string> Map of property name to value
     */
    private function extractColorTokens(string $css): array
    {
        $tokens = [];

        // Strip CSS comments
        $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);

        // Match :root blocks (possibly with media queries wrapping them)
        if (preg_match_all('/:root\s*\{([^}]+)}/', $css, $rootMatches) === false) {
            return [];
        }

        foreach ($rootMatches[1] as $block) {
            // Match custom property declarations: --color-*: <value>;
            if (preg_match_all(
                '/(--color-[\w-]+)\s*:\s*([^;]+);/i',
                $block,
                $propMatches,
                PREG_SET_ORDER,
            ) === false) {
                continue;
            }

            foreach ($propMatches as $match) {
                $tokens[trim($match[1])] = trim($match[2]);
            }
        }

        return $tokens;
    }

    private function containsVarReference(string $value): bool
    {
        return str_contains($value, 'var(');
    }
}
