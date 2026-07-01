<?php

declare(strict_types=1);

namespace Pulsar\Console;

use Pulsar\Api\Internal;
use Pulsar\Introspection\Data\ConfigSchemaEntry;
use UnitEnum;

use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_scalar;
use function sprintf;
use function str_replace;

/**
 * Renders reflected config schema entries into a Markdown reference table.
 *
 * Pure (no I/O) so the formatting is unit-tested independently of the
 * introspection pipeline that produces the schema.
 */
#[Internal]
final readonly class ConfigReferenceRenderer
{
    /**
     * @param list<ConfigSchemaEntry> $schemas
     */
    public function render(array $schemas): string
    {
        $lines = ['# Configuration reference', ''];

        foreach ($schemas as $entry) {
            $lines[] = '## ' . $entry->className;
            $lines[] = '';
            $lines[] = '| Option | Type | Default |';
            $lines[] = '| ------ | ---- | ------- |';

            foreach ($entry->properties as $property) {
                $lines[] = sprintf(
                    '| `%s` | `%s` | %s |',
                    $property->name,
                    $property->type,
                    $this->formatDefault($property->default),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function formatDefault(mixed $value): string
    {
        if ($value === null) {
            return '`null`';
        }

        if (is_bool($value)) {
            return $value ? '`true`' : '`false`';
        }

        if (is_array($value)) {
            return sprintf('`array(%d)`', count($value));
        }

        if ($value instanceof UnitEnum) {
            return sprintf('`%s::%s`', $value::class, $value->name);
        }

        if (is_scalar($value)) {
            // Escape pipes so a value never breaks the Markdown table.
            return '`' . str_replace('|', '\\|', (string) $value) . '`';
        }

        return '`(object)`';
    }
}
