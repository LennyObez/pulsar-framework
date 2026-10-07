<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Reputation level tiers derived from a user's cumulative reputation score.
 * @api
 */
#[Api(since: '1.0.0')]
enum ReputationLevel: int
{
    case Newcomer = 0;
    case Contributor = 10;
    case Regular = 50;
    case Trusted = 100;
    case Veteran = 250;
    case Expert = 500;
    case Champion = 1000;

    /**
     * Determine the reputation level for a given score.
     *
     * Iterates levels in descending order, returning the highest
     * level whose threshold the score meets or exceeds.
     */
    public static function fromScore(int $score): self
    {
        $levels = [
            self::Champion,
            self::Expert,
            self::Veteran,
            self::Trusted,
            self::Regular,
            self::Contributor,
            self::Newcomer,
        ];

        foreach ($levels as $level) {
            if ($score >= $level->value) {
                return $level;
            }
        }

        return self::Newcomer;
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Newcomer => 'Newcomer',
            self::Contributor => 'Contributor',
            self::Regular => 'Regular',
            self::Trusted => 'Trusted',
            self::Veteran => 'Veteran',
            self::Expert => 'Expert',
            self::Champion => 'Champion',
        };
    }

    /**
     * Minimum score required to reach this level.
     */
    public function minimumScore(): int
    {
        return $this->value;
    }
}
