<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Internal;

use function array_map;
use function in_array;
use function ltrim;
use function strtolower;

/**
 * Masks PII values in query binding arrays.
 */
#[Internal(reason: 'Implementation detail of SQL monitoring')]
final readonly class PiiMasker
{
    private const string MASK = '***MASKED***';

    /** @var list<string> */
    private array $normalizedColumns;

    /**
     * @param list<string> $piiColumns
     */
    public function __construct(array $piiColumns)
    {
        $this->normalizedColumns = array_map(strtolower(...), $piiColumns);
    }

    /**
     * Mask PII column values in a binding array.
     *
     * @param array<string|int, mixed> $bindings
     *
     * @return array<string|int, mixed>
     */
    public function mask(array $bindings): array
    {
        if ($this->normalizedColumns === []) {
            return $bindings;
        }

        /** @var array<string|int, mixed> $masked */
        $masked = [];

        /** @var mixed $value */
        foreach ($bindings as $key => $value) {
            $columnName = strtolower(ltrim((string) $key, ':'));

            $masked[$key] = in_array($columnName, $this->normalizedColumns, true)
                ? self::MASK
                : $value;
        }

        return $masked;
    }
}
