<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\LiveCss;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\LiveCss\ThemeToken;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;
use function strtolower;

/**
 * Resolves editable design tokens from a theme's manifest settings
 * and validates proposed token values by type.
 */
#[Internal(reason: 'Use ThemeTokenResolverInterface for public API')]
final readonly class ThemeTokenResolver implements ThemeTokenResolverInterface
{
    public function __construct(
        private ThemeRepositoryInterface $themeRepository,
    ) {}

    public function getEditableTokens(string $themeId): array
    {
        $theme = $this->themeRepository->findById($themeId);

        if ($theme === null) {
            throw CmsException::themeNotFound($themeId);
        }

        // Read the theme's manifest from storage
        $manifestPath = $theme->storagePath . '/theme.json';

        if (!file_exists($manifestPath)) {
            return [];
        }

        $manifestJson = file_get_contents($manifestPath);

        if ($manifestJson === false) {
            return [];
        }

        /** @var array<string, mixed> $manifestData */
        $manifestData = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);

        $tokenDefs = $manifestData['editable_tokens'] ?? [];

        if (!is_array($tokenDefs)) {
            return [];
        }

        $tokens = [];

        foreach ($tokenDefs as $def) {
            if (!is_array($def) || !isset($def['name'], $def['type'])) {
                continue;
            }

            /** @var array<string, mixed> $def */
            $rawConstraints = $def['constraints'] ?? [];
            /** @var array<string, mixed> $constraintsArray */
            $constraintsArray = is_array($rawConstraints) ? $rawConstraints : [];

            $tokens[] = new ThemeToken(
                name: (is_string($def['name']) ? $def['name'] : ''),
                type: (is_string($def['type']) ? $def['type'] : ''),
                default: is_string($def['default'] ?? null) ? $def['default'] : '',
                label: is_string($def['label'] ?? null) ? $def['label'] : (is_string($def['name'] ?? null) ? $def['name'] : ''),
                group: is_string($def['group'] ?? null) ? $def['group'] : 'General',
                constraints: $constraintsArray,
            );
        }

        return $tokens;
    }

    public function validateTokenValue(ThemeToken $token, string $value): bool
    {
        return match ($token->type) {
            'color' => $this->validateColor($value),
            'font' => $this->validateFont($token, $value),
            'size' => $this->validateSize($token, $value),
            'string' => $this->validateString($value),
            default => false,
        };
    }

    /**
     * Validate color values: hex (#RGB, #RRGGBB), rgb(), hsl().
     * Reject url(), expression(), javascript:.
     */
    private function validateColor(string $value): bool
    {
        if ($this->containsDangerousConstruct($value)) {
            return false;
        }

        // #RGB or #RRGGBB or #RRGGBBAA
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return true;
        }

        // rgb(r, g, b) or rgba(r, g, b, a)
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(\s*,\s*[\d.]+)?\s*\)$/i', $value)) {
            return true;
        }

        // hsl(h, s%, l%) or hsla(h, s%, l%, a)
        if (preg_match('/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%(\s*,\s*[\d.]+)?\s*\)$/i', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Validate font values: if allowed_values constraint exists, value must match.
     * Otherwise validate as a CSS font-family (no url()).
     */
    private function validateFont(ThemeToken $token, string $value): bool
    {
        if ($this->containsDangerousConstruct($value)) {
            return false;
        }

        /** @var list<string>|null $allowedValues */
        $allowedValues = $token->constraints['allowed_values'] ?? null;

        if (is_array($allowedValues) && $allowedValues !== []) {
            return in_array($value, $allowedValues, true);
        }

        // Generic font-family validation: no url() allowed
        $lower = strtolower($value);

        return !str_contains($lower, 'url(');
    }

    /**
     * Validate size values: CSS lengths (px, rem, em, vh, vw, %).
     * Check min/max constraints if provided.
     */
    private function validateSize(ThemeToken $token, string $value): bool
    {
        if (!preg_match('/^(-?[\d.]+)(px|rem|em|vh|vw|%)$/', $value, $matches)) {
            return false;
        }

        $numericValue = (float) $matches[1];

        if (array_key_exists('min', $token->constraints)) {
            $min = $token->constraints['min'];

            if (is_numeric($min) && $numericValue < (float) $min) {
                return false;
            }
        }

        if (array_key_exists('max', $token->constraints)) {
            $max = $token->constraints['max'];

            if (is_numeric($max) && $numericValue > (float) $max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate string values: accept any string but ensure CSS-safety
     * by rejecting dangerous constructs.
     */
    private function validateString(string $value): bool
    {
        return !$this->containsDangerousConstruct($value);
    }

    /**
     * Check for constructs that could enable injection attacks.
     */
    private function containsDangerousConstruct(string $value): bool
    {
        $lower = strtolower($value);

        return str_contains($lower, 'url(')
            || str_contains($lower, 'expression(')
            || str_contains($lower, 'javascript:');
    }
}
