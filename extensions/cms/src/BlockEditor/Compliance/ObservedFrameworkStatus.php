<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Compliance;

use NoDiscard;
use Pulsar\Api\Api;

use function round;

/**
 * What one compliance report observed about one framework.
 *
 * Every field is a count or a timestamp taken from an assessment run. There is
 * deliberately no field for a conformity claim, a certification, a seal or a
 * label an author chose: this object exists so that a publishable badge has
 * nothing to render except what a probe measured.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ObservedFrameworkStatus
{
    /**
     * @param non-empty-string $frameworkKey Report key, e.g. `soc2`
     * @param non-empty-string $label        Display name, e.g. `SOC 2`
     * @param int<0, max>      $assessed     Controls that counted toward coverage
     * @param int<0, max>      $satisfied    Observed working in the assessed deployment
     * @param int<0, max>      $partial      Partly observed; a residual gap was named
     * @param int<0, max>      $gaps         Claimed by the framework, not shown by the deployment
     * @param int<0, max>      $operatorChecklist Controls discharged outside the software
     * @param non-empty-string $generatedAt  ISO-8601 timestamp of the assessment run
     * @param string           $environment  Environment the assessment ran against
     */
    public function __construct(
        public string $frameworkKey,
        public string $label,
        public int $assessed,
        public int $satisfied,
        public int $partial,
        public int $gaps,
        public int $operatorChecklist,
        public string $generatedAt,
        public string $environment,
    ) {}

    /**
     * Share of assessed controls observed working, to one decimal place.
     *
     * The denominator is the probed controls only. Operator-responsibility
     * controls are excluded, because folding a checklist the framework never
     * assessed into a coverage figure is how a mostly-manual standard comes to
     * read as mostly covered.
     */
    #[NoDiscard]
    public function coveragePercent(): float
    {
        if ($this->assessed === 0) {
            return 0.0;
        }

        return round($this->satisfied / $this->assessed * 100, 1);
    }

    /**
     * Whether anything was actually probed for this framework.
     *
     * A framework whose every control is an operator responsibility has been
     * assessed and has no software finding to publish. Rendering "0 of 0" for it
     * would read as a failure rather than as what it is.
     */
    #[NoDiscard]
    public function hasProbedControls(): bool
    {
        return $this->assessed > 0;
    }
}
