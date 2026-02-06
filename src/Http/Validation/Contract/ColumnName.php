<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Contract;

use Pulsar\Api\Api;

use function in_array;
use function preg_match;
use function strtoupper;

/**
 * Validated column name value object.
 *
 * Accepts only alphanumeric + underscore identifiers that are not SQL keywords.
 */
#[Api(since: '1.0.0')]
readonly class ColumnName
{
    private const array SQL_KEYWORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TABLE', 'INDEX', 'FROM', 'WHERE', 'JOIN', 'UNION', 'GRANT',
        'REVOKE', 'TRUNCATE', 'EXEC', 'EXECUTE', 'INTO', 'VALUES',
        'SET', 'ORDER', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET',
    ];

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value) !== 1) {
            throw InvalidIdentifierException::forColumn($value);
        }

        if (in_array(strtoupper($value), self::SQL_KEYWORDS, true)) {
            throw InvalidIdentifierException::forColumn($value);
        }

        $this->value = $value;
    }
}
