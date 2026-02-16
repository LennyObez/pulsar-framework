<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function ctype_alpha;
use function implode;
use function in_array;
use function lcfirst;
use function preg_replace;
use function preg_split;
use function strtolower;
use function ucfirst;

/**
 * Converts database identifiers to safe PHP class and property names.
 *
 * Handles SQL/PHP reserved words, special characters, and casing conventions.
 */
#[Api(since: '1.0.0')]
final readonly class IdentifierNormalizer
{
    /**
     * PHP reserved words that cannot be used as identifiers.
     *
     * @var list<string>
     */
    private const array PHP_RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
        'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
        'false', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once', 'instanceof',
        'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new',
        'null', 'or', 'print', 'private', 'protected', 'public', 'readonly',
        'require', 'require_once', 'return', 'self', 'static', 'switch', 'throw',
        'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor',
        'yield', 'int', 'float', 'bool', 'string', 'mixed', 'never', 'object',
    ];

    /**
     * SQL reserved words that should be suffixed when used as PHP identifiers.
     *
     * @var list<string>
     */
    private const array SQL_RESERVED = [
        'select', 'insert', 'update', 'delete', 'from', 'where', 'join', 'inner',
        'outer', 'left', 'right', 'on', 'group', 'order', 'by', 'having', 'limit',
        'offset', 'union', 'create', 'alter', 'drop', 'table', 'index', 'column',
        'primary', 'key', 'foreign', 'references', 'constraint', 'not', 'in',
        'between', 'like', 'is', 'exists', 'all', 'any', 'some', 'distinct',
        'values', 'set', 'into', 'view', 'trigger', 'procedure', 'function',
        'database', 'schema', 'grant', 'revoke', 'commit', 'rollback', 'begin',
        'transaction', 'end', 'case', 'when', 'then', 'else', 'asc', 'desc',
        'null', 'true', 'false', 'and', 'or', 'not',
    ];

    /**
     * Convert a database table name to a PascalCase PHP class name.
     *
     * Strips non-alphanumeric characters, splits on separators,
     * and suffixes reserved words.
     */
    #[NoDiscard]
    public static function toClassName(string $tableName): string
    {
        $cleaned = self::sanitize($tableName);
        $parts = self::splitIntoParts($cleaned);
        $pascal = implode('', array_map(ucfirst(...), $parts));

        if ($pascal === '') {
            return 'Entity';
        }

        if (self::isReserved($pascal)) {
            return $pascal . 'Entity';
        }

        // Class names must start with a letter
        if (! ctype_alpha($pascal[0])) {
            return 'Entity' . $pascal;
        }

        return $pascal;
    }

    /**
     * Convert a database column name to a camelCase PHP property name.
     *
     * Strips non-alphanumeric characters, splits on separators,
     * and suffixes reserved words.
     */
    #[NoDiscard]
    public static function toPropertyName(string $columnName): string
    {
        $cleaned = self::sanitize($columnName);
        $parts = self::splitIntoParts($cleaned);
        $camel = lcfirst(implode('', array_map(ucfirst(...), $parts)));

        if ($camel === '') {
            return 'field';
        }

        if (self::isReserved($camel)) {
            return $camel . 'Value';
        }

        // Property names must start with a letter or underscore
        if (! ctype_alpha($camel[0]) && $camel[0] !== '_') {
            return 'field' . ucfirst($camel);
        }

        return $camel;
    }

    /**
     * Convert a PascalCase class name to a snake_case table name.
     *
     * Pluralizes by appending 's' (simple heuristic).
     */
    #[NoDiscard]
    public static function toTableName(string $className): string
    {
        $cleaned = self::sanitize($className);

        if ($cleaned === '') {
            return 'entities';
        }

        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $cleaned));

        return str_replace('-', '_', $snake) . 's';
    }

    /**
     * Convert a camelCase or PascalCase name to a snake_case column name.
     */
    #[NoDiscard]
    public static function toColumnName(string $propertyName): string
    {
        $cleaned = self::sanitize($propertyName);

        if ($cleaned === '') {
            return 'column';
        }

        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $cleaned));

        return str_replace('-', '_', $snake);
    }

    /**
     * Remove non-alphanumeric characters except underscores and hyphens.
     */
    private static function sanitize(string $identifier): string
    {
        $result = preg_replace('/[^a-zA-Z0-9_-]/', '', $identifier);

        return $result ?? '';
    }

    /**
     * Split an identifier into word parts on underscores, hyphens, and camelCase boundaries.
     *
     * @return list<string>
     */
    private static function splitIntoParts(string $identifier): array
    {
        // First split on underscores and hyphens
        $parts = preg_split('/[_-]+/', $identifier);

        if ($parts === false) {
            return [$identifier];
        }

        $result = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            // Split camelCase boundaries within each part
            $subParts = preg_split('/(?<=[a-z])(?=[A-Z])/', $part);

            if ($subParts === false) {
                $result[] = strtolower($part);

                continue;
            }

            foreach ($subParts as $sub) {
                if ($sub !== '') {
                    $result[] = strtolower($sub);
                }
            }
        }

        return $result;
    }

    /**
     * Check if an identifier matches a PHP or SQL reserved word.
     */
    private static function isReserved(string $identifier): bool
    {
        $lower = strtolower($identifier);

        return in_array($lower, self::PHP_RESERVED, true)
            || in_array($lower, self::SQL_RESERVED, true);
    }
}
