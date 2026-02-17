<?php

declare(strict_types=1);

namespace Pulsar\Api\TypeBridge;

use Pulsar\Api\Api;

use function str_starts_with;

/**
 * Maps PHP type strings to TypeScript type representations.
 *
 * Converts PHP scalar types, arrays, nullables, and common framework
 * types into their TypeScript equivalents for client code generation.
 */
#[Api(since: '1.0.0')]
final readonly class PhpToTypeScriptMapper
{
    /**
     * Map a PHP type name to a TypeScript type string.
     */
    public function map(string $phpType): string
    {
        // Handle nullable (prefixed with ?)
        if (str_starts_with($phpType, '?')) {
            $inner = $this->map(substr($phpType, 1));

            return $inner . ' | null';
        }

        return match ($phpType) {
            'string' => 'string',
            'int', 'integer', 'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'null' => 'null',
            'void' => 'void',
            'array' => 'unknown[]',
            'mixed' => 'unknown',
            'object', 'stdClass' => 'Record<string, unknown>',
            'iterable' => 'Iterable<unknown>',
            'never' => 'never',
            'true' => 'true',
            'false' => 'false',
            'self', 'static' => 'unknown',
            default => $this->mapComplexType($phpType),
        };
    }

    /**
     * Map a PHP type with possible array syntax or class names.
     */
    private function mapComplexType(string $phpType): string
    {
        // Handle array<K, V> syntax
        if (preg_match('/^array<\s*(.+),\s*(.+)\s*>$/i', $phpType, $matches)) {
            $valueType = $this->map(trim($matches[2]));

            return 'Record<string, ' . $valueType . '>';
        }

        // Handle list<T> or array<T> syntax
        if (preg_match('/^(?:list|array)<\s*(.+)\s*>$/i', $phpType, $matches)) {
            return $this->map(trim($matches[1])) . '[]';
        }

        // Handle T[] syntax
        if (str_ends_with($phpType, '[]')) {
            $inner = substr($phpType, 0, -2);

            return $this->map($inner) . '[]';
        }

        // Handle union types (A|B)
        if (str_contains($phpType, '|')) {
            $parts = array_map(
                fn(string $part) => $this->map(trim($part)),
                explode('|', $phpType),
            );

            return implode(' | ', $parts);
        }

        // Handle intersection types (A&B): map to intersection
        if (str_contains($phpType, '&')) {
            $parts = array_map(
                fn(string $part) => $this->map(trim($part)),
                explode('&', $phpType),
            );

            return implode(' & ', $parts);
        }

        // Class name: use short name as interface reference
        if (str_contains($phpType, '\\')) {
            $parts = explode('\\', $phpType);

            return end($parts);
        }

        // Bare class name
        return $phpType;
    }
}
