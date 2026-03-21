<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Domain;

use Pulsar\Api\Api;

/**
 * eIDAS assurance levels per Art. 8.
 * @api
 */
#[Api(since: '1.0.0')]
enum LevelOfAssurance: string
{
    case Low = 'low';
    case Substantial = 'substantial';
    case High = 'high';

    /**
     * Check if this level meets or exceeds the required minimum.
     */
    public function meetsMinimum(self $required): bool
    {
        return $this->numericValue() >= $required->numericValue();
    }

    private function numericValue(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Substantial => 2,
            self::High => 3,
        };
    }
}
