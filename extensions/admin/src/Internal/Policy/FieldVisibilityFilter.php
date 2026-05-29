<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Policy;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;

use function in_array;
use function is_scalar;

/**
 * Filters field data based on visibility and redaction rules.
 *
 * Redacted fields have their values replaced with a placeholder.
 * Non-visible fields are stripped from the output.
 */
#[Internal]
final readonly class FieldVisibilityFilter
{
    private const string REDACTED_PLACEHOLDER = '••••••';

    /**
     * Filter record data for list view.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function filterForList(DataResourceInterface $resource, array $record): array
    {
        return $this->filterRecord($resource, $record, 'list');
    }

    /**
     * Filter record data for detail view.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function filterForDetail(DataResourceInterface $resource, array $record): array
    {
        return $this->filterRecord($resource, $record, 'detail');
    }

    /**
     * Filter record data for export, stripping redacted fields entirely.
     *
     * @param array<string, mixed> $record
     * @return array<string, scalar|null>
     */
    public function filterForExport(DataResourceInterface $resource, array $record): array
    {
        $exportableFields = $resource->exportableFields();
        $fields = $resource->fields();
        $filtered = [];

        foreach ($fields as $field) {
            if (!in_array($field->name, $exportableFields, true)) {
                continue;
            }
            if ($field->redacted) {
                continue;
            }
            /** @var mixed $value */
            $value = $record[$field->name] ?? null;
            $filtered[$field->name] = $this->toScalar($value);
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function filterRecord(DataResourceInterface $resource, array $record, string $context): array
    {
        $fields = $resource->fields();
        /** @var array<string, mixed> $filtered */
        $filtered = [];

        foreach ($fields as $field) {
            if (!$this->isVisible($field, $context)) {
                continue;
            }
            if ($field->redacted) {
                $filtered = [...$filtered, $field->name => self::REDACTED_PLACEHOLDER];
                continue;
            }
            $filtered = [...$filtered, $field->name => $record[$field->name] ?? null];
        }

        $pk = $resource->primaryKey();
        if (!isset($filtered[$pk]) && isset($record[$pk])) {
            $filtered = [...$filtered, $pk => $record[$pk]];
        }

        return $filtered;
    }

    private function isVisible(FieldDefinition $field, string $context): bool
    {
        return match ($context) {
            'list' => $field->visibleOnList,
            'detail' => $field->visibleOnDetail,
            'form' => $field->visibleOnForm,
            default => true,
        };
    }

    private function toScalar(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        return (string) json_encode($value, JSON_THROW_ON_ERROR);
    }
}
