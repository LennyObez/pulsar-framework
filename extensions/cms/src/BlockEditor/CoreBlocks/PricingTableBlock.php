<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function array_key_exists;
use function count;
use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class PricingTableBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'pricing-table';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plans' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'price' => ['type' => 'string'],
                            'features' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'ctaText' => ['type' => 'string'],
                            'ctaUrl' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'price', 'features', 'ctaText', 'ctaUrl'],
                    ],
                ],
                'highlighted' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['plans'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<array{name: string, price: string, features: list<string>, ctaText: string, ctaUrl: string}> $plans */
        $plans = $data['plans'] ?? [];
        $highlighted = $data['highlighted'] ?? null;

        $html = '<div class="pricing-table">';

        foreach ($plans as $i => $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $cssClass = 'pricing-plan';

            if (is_int($highlighted) && $i === $highlighted) {
                $cssClass .= ' pricing-plan--highlighted';
            }

            $name = htmlspecialchars((string) ($plan['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $price = htmlspecialchars((string) ($plan['price'] ?? ''), ENT_QUOTES, 'UTF-8');
            $ctaText = htmlspecialchars((string) ($plan['ctaText'] ?? ''), ENT_QUOTES, 'UTF-8');
            $ctaUrl = htmlspecialchars((string) ($plan['ctaUrl'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html .= "<div class=\"{$cssClass}\">";
            $html .= "<h3 class=\"pricing-plan__name\">{$name}</h3>";
            $html .= "<div class=\"pricing-plan__price\">{$price}</div>";
            $html .= '<ul class="pricing-plan__features">';

            /** @var list<string> $features */
            $features = $plan['features'] ?? [];

            if (is_array($features)) {
                foreach ($features as $feature) {
                    $html .= '<li>' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . '</li>';
                }
            }

            $html .= '</ul>';
            $html .= "<a href=\"{$ctaUrl}\" class=\"pricing-plan__cta\">{$ctaText}</a>";
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['plans']) || !is_array($data['plans'])) {
            $errors[] = 'plans is required and must be an array';

            return $errors;
        }

        foreach ($data['plans'] as $index => $plan) {
            if (!is_array($plan)) {
                $errors[] = "plans[{$index}] must be an object";

                continue;
            }

            if (!isset($plan['name']) || !is_string($plan['name'])) {
                $errors[] = "plans[{$index}].name is required and must be a string";
            }

            if (!isset($plan['price']) || !is_string($plan['price'])) {
                $errors[] = "plans[{$index}].price is required and must be a string";
            }

            if (!isset($plan['features']) || !is_array($plan['features'])) {
                $errors[] = "plans[{$index}].features is required and must be an array";
            } else {
                foreach ($plan['features'] as $featureIndex => $feature) {
                    if (!is_string($feature)) {
                        $errors[] = "plans[{$index}].features[{$featureIndex}] must be a string";
                    }
                }
            }

            if (!isset($plan['ctaText']) || !is_string($plan['ctaText'])) {
                $errors[] = "plans[{$index}].ctaText is required and must be a string";
            }

            if (!isset($plan['ctaUrl']) || !is_string($plan['ctaUrl'])) {
                $errors[] = "plans[{$index}].ctaUrl is required and must be a string";
            }
        }

        if (array_key_exists('highlighted', $data)) {
            if (!is_int($data['highlighted']) || $data['highlighted'] < 0 || $data['highlighted'] >= count($data['plans'])) {
                $errors[] = 'highlighted must be a valid plan index';
            }
        }

        return $errors;
    }
}
