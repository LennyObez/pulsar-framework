<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\License;

use Pulsar\Api\Api;

use function htmlspecialchars;

use const ENT_QUOTES;

/**
 * Renders license badge HTML for media assets.
 *
 * Generates accessible, branded markup using pui-* CSS classes
 * showing author name, license type, and optional date.
 */
#[Api(since: '1.0.0')]
final readonly class LicenseBadgeRenderer
{
    /**
     * Render a license badge for a media asset.
     *
     * @param LicenseType $license License type
     * @param string|null $authorName Display name of the author/copyright holder
     * @param string|null $copyrightHolder External copyright holder (overrides author)
     * @param string|null $date Date string for the license (e.g., "2026")
     */
    public function render(
        LicenseType $license,
        ?string $authorName = null,
        ?string $copyrightHolder = null,
        ?string $date = null,
    ): string {
        $displayName = $copyrightHolder ?? $authorName;
        $escapedLabel = self::escape($license->label());
        $escapedValue = self::escape($license->value);

        $html = '<div class="pui-license-badge" role="contentinfo" aria-label="License information">';
        $html .= '<span class="pui-license-badge__type">';

        $url = $license->url();

        if ($url !== null) {
            $escapedUrl = self::escape($url);
            $html .= '<a href="' . $escapedUrl . '" class="pui-license-badge__link" rel="license noopener" target="_blank">';
            $html .= $escapedValue;
            $html .= '</a>';
        } else {
            $html .= $escapedValue;
        }

        $html .= '</span>';

        if ($displayName !== null && $displayName !== '') {
            $escapedName = self::escape($displayName);
            $html .= ' <span class="pui-license-badge__author">' . $escapedName . '</span>';
        }

        if ($date !== null && $date !== '') {
            $escapedDate = self::escape($date);
            $html .= ' <span class="pui-license-badge__date">' . $escapedDate . '</span>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
