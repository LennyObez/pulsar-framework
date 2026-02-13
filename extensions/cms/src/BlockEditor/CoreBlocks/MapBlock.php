<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_float;
use function is_int;
use function is_string;
use function number_format;

use const ENT_QUOTES;

#[Internal]
final readonly class MapBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'map';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
                'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
                'zoom' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'caption' => ['type' => 'string'],
            ],
            'required' => ['latitude', 'longitude'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $lat = (float) ($data['latitude'] ?? 0);
        $lon = (float) ($data['longitude'] ?? 0);
        $zoom = (int) ($data['zoom'] ?? 13);
        $caption = $data['caption'] ?? null;

        if ($zoom < 1 || $zoom > 20) {
            $zoom = 13;
        }

        $latStr = number_format($lat, 6, '.', '');
        $lonStr = number_format($lon, 6, '.', '');
        $bboxMinLon = number_format($lon - 0.01, 6, '.', '');
        $bboxMinLat = number_format($lat - 0.01, 6, '.', '');
        $bboxMaxLon = number_format($lon + 0.01, 6, '.', '');
        $bboxMaxLat = number_format($lat + 0.01, 6, '.', '');

        $src = "https://www.openstreetmap.org/export/embed.html?bbox=$bboxMinLon%2C$bboxMinLat%2C$bboxMaxLon%2C$bboxMaxLat&amp;layer=mapnik&amp;marker=$latStr%2C$lonStr&amp;zoom=$zoom";

        $html = '<figure class="map">';
        $html .= "<iframe src=\"$src\" width=\"100%\" height=\"400\" sandbox=\"allow-scripts\" loading=\"lazy\" title=\"Map\" style=\"border:0\"></iframe>";

        if (is_string($caption) && $caption !== '') {
            $html .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
        }

        return $html . '</figure>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['latitude'])) {
            $errors[] = 'latitude is required';
        } elseif (!is_float($data['latitude']) && !is_int($data['latitude'])) {
            $errors[] = 'latitude must be a number';
        } elseif ($data['latitude'] < -90 || $data['latitude'] > 90) {
            $errors[] = 'latitude must be between -90 and 90';
        }

        if (!isset($data['longitude'])) {
            $errors[] = 'longitude is required';
        } elseif (!is_float($data['longitude']) && !is_int($data['longitude'])) {
            $errors[] = 'longitude must be a number';
        } elseif ($data['longitude'] < -180 || $data['longitude'] > 180) {
            $errors[] = 'longitude must be between -180 and 180';
        }

        if (isset($data['zoom'])) {
            if (!is_int($data['zoom']) || $data['zoom'] < 1 || $data['zoom'] > 20) {
                $errors[] = 'zoom must be an integer between 1 and 20';
            }
        }

        return $errors;
    }
}
