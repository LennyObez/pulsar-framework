<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\BlockEditor\Compliance\FrameworkLabels;
use Pulsar\Extension\Cms\BlockEditor\Compliance\ObservedComplianceSourceInterface;
use Pulsar\Extension\Cms\BlockEditor\Compliance\ObservedFrameworkStatus;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function number_format;
use function sprintf;

use const ENT_QUOTES;

/**
 * Publishes what a compliance assessment observed. Never a conformity claim.
 *
 * This block used to accept an arbitrary framework key, an arbitrary label and
 * an arbitrary `status` string, and render them into a public page. A site built
 * on Pulsar could therefore publish "NIST CSF — Certified" to the open internet
 * with nothing whatsoever behind it, and the framework would render it happily.
 * NIST CSF was in fact on that list while the framework had no backup or restore
 * primitive at all, which is the one thing NIST CSF's Recover function is about.
 *
 * A badge asserting conformity to a standard has no defensible form, because
 * conformity is asserted by an assessor after an audit and not by a CMS. What
 * does have a defensible form is a badge that reports a measurement, attributed
 * and dated. So the author now chooses only WHICH framework to show and where to
 * link; every word of the outcome comes from
 * {@see ObservedComplianceSourceInterface}, which reads an assessment run against
 * the deployment.
 *
 * Three properties keep it that way, and each is load-bearing:
 *
 * - **No author-supplied text reaches the outcome.** `label`, `status` and
 *   `logoUrl` were removed rather than validated. A free-text status is an
 *   unbounded claim, and a logo is a stronger claim than the text beside it —
 *   an uploaded seal reading "SOC 2 CERTIFIED" would have sailed through any
 *   validation that only checked the field was a string.
 * - **A framework nobody assessed renders as unassessed**, naming itself, rather
 *   than silently disappearing or falling back to a bare name. A bare framework
 *   name on a marketing page reads as a claim to the reader.
 * - **The assessment date is always rendered.** A badge is a snapshot of a
 *   deployment at a moment; one that hides its age asserts something about the
 *   present that it cannot support.
 */
#[Internal]
final readonly class ComplianceBadgeBlock implements BlockTypeInterface
{
    private const array VALID_LAYOUTS = ['inline', 'grid', 'stacked'];
    private const array VALID_SIZES = ['sm', 'md', 'lg'];

    public function __construct(
        private ObservedComplianceSourceInterface $observed,
    ) {}

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
                    'description' => 'Frameworks to report on. The outcome shown for each is read '
                        . 'from the deployment\'s compliance report and cannot be set here.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'framework' => [
                                'type' => 'string',
                                'description' => 'Report framework key, e.g. soc2, pci_dss, iso27001.',
                            ],
                            'url' => [
                                'type' => 'string',
                                'format' => 'uri',
                                'description' => 'Optional link, e.g. to your published report or trust page.',
                            ],
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
        /** @var mixed $title */
        $title = $data['title'] ?? null;

        $html = "<div class=\"compliance-badge-block compliance-badge-block--$layout compliance-badge-block--$size\">";

        if (is_string($title) && $title !== '') {
            $html .= sprintf(
                '<h4 class="compliance-badge-block__title">%s</h4>',
                self::escape($title),
            );
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
        /** @var mixed $rawFramework */
        $rawFramework = $badge['framework'] ?? null;

        if (!is_string($rawFramework) || $rawFramework === '') {
            return '';
        }

        // A key no report can emit is not a framework this site may name.
        $framework = FrameworkLabels::canonical($rawFramework);

        if ($framework === null) {
            return '';
        }

        $label = FrameworkLabels::for($framework) ?? $framework;
        $status = $this->observed->statusFor($framework);

        $content = sprintf(
            '<span class="compliance-badge-block__label">%s</span>',
            self::escape($label),
        );

        $content .= $status === null
            ? '<span class="compliance-badge-block__state compliance-badge-block__state--unassessed">'
                . 'not assessed</span>'
            : self::renderObserved($status);

        $modifier = self::escape($framework);
        $state = $status === null ? 'unassessed' : 'observed';

        /** @var mixed $url */
        $url = $badge['url'] ?? null;

        if (is_string($url) && $url !== '') {
            return sprintf(
                '<a href="%s" class="compliance-badge-block__badge compliance-badge-block__badge--%s '
                    . 'compliance-badge-block__badge--%s" rel="noopener noreferrer">%s</a>',
                self::escape($url),
                $modifier,
                $state,
                $content,
            );
        }

        return sprintf(
            '<div class="compliance-badge-block__badge compliance-badge-block__badge--%s '
                . 'compliance-badge-block__badge--%s">%s</div>',
            $modifier,
            $state,
            $content,
        );
    }

    /**
     * The observed part: counts and a date, and nothing that reads as a verdict.
     */
    private static function renderObserved(ObservedFrameworkStatus $status): string
    {
        $html = '';

        if ($status->hasProbedControls()) {
            $html .= sprintf(
                '<span class="compliance-badge-block__state compliance-badge-block__state--observed">'
                    . '%d of %d controls observed (%s%%)</span>',
                $status->satisfied,
                $status->assessed,
                number_format($status->coveragePercent(), 1),
            );
        } else {
            // Every control for this framework is discharged outside the
            // software. Saying "0 of 0" would read as a failure; saying nothing
            // would let the framework name stand alone as a claim.
            $html .= '<span class="compliance-badge-block__state compliance-badge-block__state--checklist">'
                . 'no software controls assessed</span>';
        }

        if ($status->operatorChecklist > 0) {
            $html .= sprintf(
                '<span class="compliance-badge-block__checklist">%d organizational control%s not assessed here</span>',
                $status->operatorChecklist,
                $status->operatorChecklist === 1 ? '' : 's',
            );
        }

        $suffix = $status->environment !== '' && $status->environment !== 'production'
            ? sprintf(' (%s)', self::escape($status->environment))
            : '';

        return $html . sprintf(
            '<time class="compliance-badge-block__assessed" datetime="%s">assessed %s%s</time>',
            self::escape($status->generatedAt),
            self::escape($status->generatedAt),
            $suffix,
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

            $framework = $badge['framework'] ?? null;

            if (!is_string($framework) || $framework === '') {
                $errors[] = "badges[$index].framework is required and must be a string";

                continue;
            }

            if (FrameworkLabels::for($framework) === null) {
                $errors[] = "badges[$index].framework '$framework' is not a framework the compliance "
                    . 'report can produce a finding for';
            }

            if (isset($badge['url']) && !is_string($badge['url'])) {
                $errors[] = "badges[$index].url must be a string";
            }

            // Rejected rather than ignored: an author who typed one of these
            // meant to publish a claim, and silently dropping it would leave
            // them believing the page says something it does not.
            foreach (['label', 'status', 'logoUrl'] as $removed) {
                if (isset($badge[$removed])) {
                    $errors[] = "badges[$index].$removed is not supported: a compliance badge renders "
                        . 'only what an assessment observed, never author-supplied text or imagery';
                }
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
