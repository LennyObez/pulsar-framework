<?php

declare(strict_types=1);

namespace Pulsar\I18n\Region;

use Pulsar\Api\Api;

/**
 * Geographic continent classification for country grouping.
 *
 * Used by the region selector to organize countries into navigable groups.
 * Follows the UN geoscheme seven-continent model.
 * @api
 */
#[Api(since: '1.0.0')]
enum Continent: string
{
    case Africa = 'africa';
    case Asia = 'asia';
    case Europe = 'europe';
    case NorthAmerica = 'north_america';
    case SouthAmerica = 'south_america';
    case Oceania = 'oceania';
    case Antarctica = 'antarctica';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Africa => 'Africa',
            self::Asia => 'Asia',
            self::Europe => 'Europe',
            self::NorthAmerica => 'North America',
            self::SouthAmerica => 'South America',
            self::Oceania => 'Oceania',
            self::Antarctica => 'Antarctica',
        };
    }

    /**
     * Display order (Europe first as primary market, then alphabetical).
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Europe => 0,
            self::NorthAmerica => 1,
            self::SouthAmerica => 2,
            self::Asia => 3,
            self::Africa => 4,
            self::Oceania => 5,
            self::Antarctica => 6,
        };
    }
}
