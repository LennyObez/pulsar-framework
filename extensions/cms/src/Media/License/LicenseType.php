<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\License;

use Pulsar\Api\Api;

/**
 * Supported content license types for media assets.
 */
#[Api(since: '1.0.0')]
enum LicenseType: string
{
    case CcBy = 'CC-BY';
    case CcBySa = 'CC-BY-SA';
    case CcByNc = 'CC-BY-NC';
    case CcByNcSa = 'CC-BY-NC-SA';
    case CcByNd = 'CC-BY-ND';
    case CcByNcNd = 'CC-BY-NC-ND';
    case CcZero = 'CC0';
    case AllRightsReserved = 'All Rights Reserved';
    case Custom = 'Custom';

    /**
     * Human-readable label for the license.
     */
    public function label(): string
    {
        return match ($this) {
            self::CcBy => 'Creative Commons Attribution',
            self::CcBySa => 'Creative Commons Attribution-ShareAlike',
            self::CcByNc => 'Creative Commons Attribution-NonCommercial',
            self::CcByNcSa => 'Creative Commons Attribution-NonCommercial-ShareAlike',
            self::CcByNd => 'Creative Commons Attribution-NoDerivatives',
            self::CcByNcNd => 'Creative Commons Attribution-NonCommercial-NoDerivatives',
            self::CcZero => 'CC0 Public Domain Dedication',
            self::AllRightsReserved => 'All Rights Reserved',
            self::Custom => 'Custom License',
        };
    }

    /**
     * URL to the license deed, or null for custom/ARR.
     */
    public function url(): ?string
    {
        return match ($this) {
            self::CcBy => 'https://creativecommons.org/licenses/by/4.0/',
            self::CcBySa => 'https://creativecommons.org/licenses/by-sa/4.0/',
            self::CcByNc => 'https://creativecommons.org/licenses/by-nc/4.0/',
            self::CcByNcSa => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
            self::CcByNd => 'https://creativecommons.org/licenses/by-nd/4.0/',
            self::CcByNcNd => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
            self::CcZero => 'https://creativecommons.org/publicdomain/zero/1.0/',
            self::AllRightsReserved, self::Custom => null,
        };
    }

    /**
     * Whether the license is a Creative Commons variant.
     */
    public function isCreativeCommons(): bool
    {
        return $this !== self::AllRightsReserved && $this !== self::Custom;
    }
}
