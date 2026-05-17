<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Contract\ColumnName;
use Pulsar\Http\Validation\Contract\TableName;
use Pulsar\Http\Validation\Contract\ValidationQueryPort;
use Pulsar\Http\Validation\Contract\WhereConditions;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function sprintf;

/**
 * Value must be unique in the database table/column. Skips null values.
 */
#[Api(since: '1.0.0')]
final readonly class Unique implements RuleInterface
{
    public function __construct(
        private ValidationQueryPort $port,
        private TableName $table,
        private ColumnName $column,
        private WhereConditions $exclude = new WhereConditions(),
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($this->port->isUnique($this->table, $this->column, $value, $this->exclude)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s has already been taken.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'unique';
    }
}
