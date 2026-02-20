<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\LiveCss;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverride;

use function sprintf;
use function trim;

/**
 * Generates inline style blocks from CSS overrides and computes
 * matching CSP directives.
 */
#[Internal(reason: 'Internal utility for template rendering')]
final readonly class LiveCssInjector
{
    public function __construct(
        private CspHashComputerInterface $hashComputer,
    ) {}

    /**
     * Generate a complete <style> block content combining token overrides
     * and custom CSS.
     */
    public function generateStyleBlock(CssOverride $override): string
    {
        $parts = [];

        if ($override->tokenOverrides !== []) {
            $declarations = [];

            foreach ($override->tokenOverrides as $name => $value) {
                $declarations[] = sprintf('  %s: %s;', $name, $value);
            }

            $parts[] = ":root {\n" . implode("\n", $declarations) . "\n}";
        }

        $customCss = trim($override->cssContent);

        if ($customCss !== '') {
            $parts[] = $customCss;
        }

        return implode("\n", $parts);
    }

    /**
     * Generate a CSP style-src directive value for an inline style block.
     */
    public function generateCspDirective(string $styleContent): string
    {
        $hash = $this->hashComputer->computeHash($styleContent);

        return sprintf("style-src 'self' '%s'", $hash);
    }
}
