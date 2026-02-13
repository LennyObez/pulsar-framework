<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Template;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function lcfirst;
use function ltrim;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function strtolower;
use function ucwords;

/**
 * Safe template renderer using placeholder substitution only.
 *
 * Supports `{{variableName}}` placeholders with optional case filters:
 * - `{{name|PascalCase}}`
 * - `{{name|camelCase}}`
 * - `{{name|snake_case}}`
 * - `{{name|kebab-case}}`
 *
 * No eval(). No arbitrary code execution. Deterministic output.
 */
#[Api(since: '1.0.0')]
final readonly class TemplateRenderer
{
    /**
     * Render a template string by substituting variables.
     *
     * @param string $template The template with `{{variable}}` placeholders
     * @param list<TemplateVariable> $variables Variables to substitute
     *
     * @throws InvalidArgumentException If a filter is unknown
     */
    public function render(string $template, array $variables): string
    {
        $placeholders = $this->extractPlaceholders($template);

        if ($placeholders === []) {
            return $template;
        }

        $variableMap = $this->buildVariableMap($variables);

        $search = [];
        $replace = [];

        foreach ($placeholders as [$fullMatch, $name, $filter]) {
            $value = $variableMap[$name] ?? '';
            $transformed = $this->applyFilter($value, $filter);
            $search[] = $fullMatch;
            $replace[] = $transformed;
        }

        return str_replace($search, $replace, $template);
    }

    /**
     * Extract all `{{name}}` and `{{name|filter}}` placeholders from a template.
     *
     * @return list<array{0: string, 1: string, 2: string}> Each entry: [fullMatch, name, filter]
     */
    private function extractPlaceholders(string $template): array
    {
        $pattern = '/\{\{(\w+)(?:\|(\w[\w-]*))?}}/';
        $matches = [];
        $count = preg_match_all($pattern, $template, $matches, PREG_SET_ORDER);

        if ($count === 0 || $count === false) {
            return [];
        }

        $result = [];
        foreach ($matches as $match) {
            $result[] = [$match[0], $match[1], $match[2] ?? ''];
        }

        return $result;
    }

    /**
     * @param list<TemplateVariable> $variables
     * @return array<string, string>
     */
    private function buildVariableMap(array $variables): array
    {
        $map = [];
        foreach ($variables as $variable) {
            $map[$variable->name] = $variable->value;
        }

        return $map;
    }

    /**
     * Apply a case-transformation filter to a value.
     *
     * @throws InvalidArgumentException If the filter is unknown
     */
    private function applyFilter(string $value, string $filter): string
    {
        if ($filter === '') {
            return $value;
        }

        return match ($filter) {
            'PascalCase' => $this->toPascalCase($value),
            'camelCase' => $this->toCamelCase($value),
            'snake_case' => $this->toSnakeCase($value),
            'kebab-case' => $this->toKebabCase($value),
            default => throw new InvalidArgumentException(
                "Unknown template filter: $filter. Supported: PascalCase, camelCase, snake_case, kebab-case.",
            ),
        };
    }

    private function toPascalCase(string $value): string
    {
        $normalized = $this->normalizeWords($value);

        return str_replace(' ', '', ucwords($normalized, ' '));
    }

    private function toCamelCase(string $value): string
    {
        return lcfirst($this->toPascalCase($value));
    }

    private function toSnakeCase(string $value): string
    {
        $normalized = $this->normalizeWords($value);

        return str_replace(' ', '_', strtolower($normalized));
    }

    private function toKebabCase(string $value): string
    {
        $normalized = $this->normalizeWords($value);

        return str_replace(' ', '-', strtolower($normalized));
    }

    /**
     * Normalize a value into space-separated words by splitting on
     * camelCase boundaries, underscores, hyphens, and spaces.
     */
    private function normalizeWords(string $value): string
    {
        // Insert space before uppercase letters that follow lowercase letters (camelCase split)
        $spaced = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $value);

        // Replace underscores, hyphens, and multiple spaces with a single space
        $normalized = (string) preg_replace('/[_\s-]+/', ' ', $spaced);

        return ltrim($normalized);
    }
}
