<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class ComplianceBadgeBlock implements BlockTypeInterface
{
    private const array KNOWN_FRAMEWORKS = [
        'soc2' => 'SOC 2',
        'hipaa' => 'HIPAA',
        'gdpr' => 'GDPR',
        'pci-dss' => 'PCI DSS',
        'iso-27001' => 'ISO 27001',
        'iso-42001' => 'ISO 42001',
        'iso-13485' => 'ISO 13485',
        'nist-csf' => 'NIST CSF',
        'ccpa' => 'CCPA',
        'dora' => 'DORA',
        'psd2' => 'PSD2',
        'eidas' => 'eIDAS',
        'nis2' => 'NIS2',
        'hl7-fhir' => 'HL7 FHIR',
        'mdr' => 'MDR',
        'swift-csp' => 'SWIFT CSP',
    ];

    private const array VALID_LAYOUTS = ['inline', 'grid', 'stacked'];
    private const array VALID_SIZES = ['sm', 'md', 'lg'];

    #[Override]
    public function type(): string
    {
        return 'compliance-badge';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'badges' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'framework' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'logoUrl' => ['type' => 'string', 'format' => 'uri'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'status' => ['type' => 'string'],
                        ],
                        'required' => ['framework'],
                    ],
                ],
                'layout' => ['type' => 'string', 'enum' => self::VALID_LAYOUTS],
                'size' => ['type' => 'string', 'enum' => self::VALID_SIZES],
                'title' => ['type' => 'string'],
            ],
            'required' => ['badges'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<mixed> $badges */
        $badges = $data['badges'] ?? [];
        $layout = is_string($data['layout'] ?? null) && in_array($data['layout'], self::VALID_LAYOUTS, true)
            ? $data['layout']
            : 'inline';
        $size = is_string($data['size'] ?? null) && in_array($data['size'], self::VALID_SIZES, true)
            ? $data['size']
            : 'md';
        $title = $data['title'] ?? null;

        $html = "<div class=\"compliance-badge-block compliance-badge-block--$layout compliance-badge-block--$size\">";

        if (is_string($title) && $title !== '') {
            $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $html .= "<h4 class=\"compliance-badge-block__title\">$escapedTitle</h4>";
        }

        $html .= '<div class="compliance-badge-block__badges">';

        foreach ($badges as $badge) {
            if (!is_array($badge)) {
                continue;
            }

            /** @var array<string, mixed> $badge */
            $html .= $this->renderBadge($badge);
        }

        return $html . '</div></div>';
    }

    /**
     * @param array<string, mixed> $badge
     */
    private function renderBadge(array $badge): string
    {
        $framework = is_string($badge['framework'] ?? null) ? $badge['framework'] : '';
        $label = is_string($badge['label'] ?? null)
            ? $badge['label']
            : (self::KNOWN_FRAMEWORKS[$framework] ?? $framework);
        $escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $escapedFramework = htmlspecialchars($framework, ENT_QUOTES, 'UTF-8');
        $logoUrl = $badge['logoUrl'] ?? null;
        $url = $badge['url'] ?? null;
        $status = $badge['status'] ?? null;

        $content = '';

        if (is_string($logoUrl) && $logoUrl !== '') {
            $escapedLogo = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
            $content .= "<img src=\"$escapedLogo\" alt=\"\" class=\"compliance-badge-block__logo\" loading=\"lazy\">";
        }

        $content .= "<span class=\"compliance-badge-block__label\">$escapedLabel</span>";

        if (is_string($status) && $status !== '') {
            $escapedStatus = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
            $content .= "<span class=\"compliance-badge-block__status\">$escapedStatus</span>";
        }

        if (is_string($url) && $url !== '') {
            $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

            return "<a href=\"$escapedUrl\" class=\"compliance-badge-block__badge compliance-badge-block__badge--$escapedFramework\" rel=\"noopener noreferrer\">$content</a>";
        }

        return "<div class=\"compliance-badge-block__badge compliance-badge-block__badge--$escapedFramework\">$content</div>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['badges']) || !is_array($data['badges'])) {
            $errors[] = 'badges is required and must be an array';

            return $errors;
        }

        foreach ($data['badges'] as $index => $badge) {
            if (!is_array($badge)) {
                $errors[] = "badges[$index] must be an object";

                continue;
            }

            if (!isset($badge['framework']) || !is_string($badge['framework'])) {
                $errors[] = "badges[$index].framework is required and must be a string";
            }

            if (isset($badge['label']) && !is_string($badge['label'])) {
                $errors[] = "badges[$index].label must be a string";
            }

            if (isset($badge['logoUrl']) && !is_string($badge['logoUrl'])) {
                $errors[] = "badges[$index].logoUrl must be a string";
            }

            if (isset($badge['url']) && !is_string($badge['url'])) {
                $errors[] = "badges[$index].url must be a string";
            }
        }

        if (isset($data['layout']) && !in_array($data['layout'], self::VALID_LAYOUTS, true)) {
            $errors[] = 'layout must be one of: inline, grid, stacked';
        }

        if (isset($data['size']) && !in_array($data['size'], self::VALID_SIZES, true)) {
            $errors[] = 'size must be one of: sm, md, lg';
        }

        if (isset($data['title']) && !is_string($data['title'])) {
            $errors[] = 'title must be a string';
        }

        return $errors;
    }
}
