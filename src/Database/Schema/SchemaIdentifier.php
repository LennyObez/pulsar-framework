<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

use function in_array;
use function mb_strlen;
use function preg_match;
use function strtolower;

/**
 * Centralized identifier validation for schema DDL operations.
 *
 * Validates table, column, index, and foreign key names against a strict
 * pattern and rejects SQL reserved words to prevent injection and ambiguity.
 */
#[Api(since: '1.0.0')]
final class SchemaIdentifier
{
    private const string PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/';

    /** @var list<string> */
    private const array RESERVED = [
        'select', 'from', 'where', 'table', 'column', 'index',
        'create', 'drop', 'alter', 'insert', 'update', 'delete', 'order', 'group',
        'having', 'join', 'on', 'as', 'and', 'or', 'not', 'null', 'true', 'false',
        'primary', 'foreign', 'key', 'references', 'constraint', 'unique', 'check',
        'default', 'set', 'values', 'into', 'exists', 'between', 'like', 'in', 'is',
        'case', 'when', 'then', 'else', 'end', 'limit', 'offset', 'union', 'all',
    ];

    public static function validateTable(string $name): void
    {
        self::validate('table', $name);
    }

    public static function validateColumn(string $name): void
    {
        self::validate('column', $name);
    }

    public static function validateIndex(string $name): void
    {
        self::validate('index', $name);
    }

    public static function validateForeignKey(string $name): void
    {
        self::validate('foreign key', $name);
    }

    private static function validate(string $type, string $name): void
    {
        if ($name === '') {
            throw SchemaException::invalidIdentifier($type, $name, 'identifier must not be empty');
        }

        if (mb_strlen($name) > 64) {
            throw SchemaException::invalidIdentifier($type, $name, 'identifier must not exceed 64 characters');
        }

        if (preg_match(self::PATTERN, $name) !== 1) {
            throw SchemaException::invalidIdentifier(
                $type,
                $name,
                'identifier must start with a letter or underscore and contain only letters, digits, and underscores',
            );
        }

        if (in_array(strtolower($name), self::RESERVED, true)) {
            throw SchemaException::invalidIdentifier($type, $name, 'identifier is a reserved SQL word');
        }
    }
}
