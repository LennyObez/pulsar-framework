<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class CarouselBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'carousel';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slides' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'imageUrl' => ['type' => 'string'],
                            'alt' => ['type' => 'string'],
                            'caption' => ['type' => 'string'],
                        ],
                        'required' => ['imageUrl', 'alt'],
                    ],
                ],
                'autoplay' => ['type' => 'boolean'],
                'interval' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['slides'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $slides */
        $slides = $data['slides'] ?? [];
        $autoplay = ($data['autoplay'] ?? false) === true ? 'true' : 'false';
        $interval = is_int($data['interval'] ?? null) ? $data['interval'] : 5000;

        if ($interval < 1) {
            $interval = 5000;
        }

        $anchor = isset($data['anchor']) && is_string($data['anchor']) ? ' id="' . htmlspecialchars($data['anchor'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $className = isset($data['className']) && is_string($data['className']) ? ' ' . htmlspecialchars($data['className'], ENT_QUOTES, 'UTF-8') : '';
        $slideCount = 0;

        foreach ($slides as $slide) {
            if (is_array($slide)) {
                $slideCount++;
            }
        }

        $html = "<div class=\"carousel$className\" data-autoplay=\"$autoplay\" data-interval=\"$interval\" aria-roledescription=\"carousel\" aria-label=\"Image carousel\"$anchor>";
        $html .= '<div class="carousel__slides" aria-live="polite">';

        $slideIndex = 0;

        foreach ($slides as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $slideIndex++;
            $imageUrl = htmlspecialchars(is_string($slide['imageUrl'] ?? null) ? $slide['imageUrl'] : '', ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars(is_string($slide['alt'] ?? null) ? $slide['alt'] : '', ENT_QUOTES, 'UTF-8');
            $caption = $slide['caption'] ?? null;

            $html .= "<div class=\"carousel__slide\" role=\"group\" aria-roledescription=\"slide\" aria-label=\"Slide $slideIndex of $slideCount\">";
            $html .= "<img src=\"$imageUrl\" alt=\"$alt\">";

            if (is_string($caption) && $caption !== '') {
                $html .= '<p class="carousel__caption">' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div class="carousel__nav" role="group" aria-label="Slide navigation">';

        $dotIndex = 0;

        foreach ($slides as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $dotIndex++;
            $html .= "<button class=\"carousel__dot\" aria-label=\"Go to slide $dotIndex\"></button>";
        }

        return $html . '</div></div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['slides']) || !is_array($data['slides'])) {
            $errors[] = 'slides is required and must be an array';

            return $errors;
        }

        foreach ($data['slides'] as $index => $slide) {
            if (!is_array($slide)) {
                $errors[] = "slides[$index] must be an object";

                continue;
            }

            if (!isset($slide['imageUrl']) || !is_string($slide['imageUrl'])) {
                $errors[] = "slides[$index].imageUrl is required and must be a string";
            }

            if (!isset($slide['alt']) || !is_string($slide['alt'])) {
                $errors[] = "slides[$index].alt is required and must be a string";
            }
        }

        if (isset($data['interval']) && (!is_int($data['interval']) || $data['interval'] < 1)) {
            $errors[] = 'interval must be a positive integer';
        }

        return $errors;
    }
}
