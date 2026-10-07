<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Turns one assessment into one document.
 *
 * A renderer receives findings and never evidence-gathering collaborators, so
 * no format can reach past the assessment for a fact the others did not see.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface ReportRendererInterface
{
    /**
     * The rendered document, without a trailing newline of its own.
     */
    #[NoDiscard]
    public function render(ComplianceAssessment $assessment): string;
}
