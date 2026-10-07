<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use Pulsar\Api\Api;

/**
 * The wording every Pulsar compliance artefact carries, in one place so the
 * three renderers cannot drift into three different promises.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ReportDisclaimer
{
    /**
     * Kept verbatim from {@see \Pulsar\Compliance\Verification\VerificationReport},
     * which has carried this sentence since 1.0.0.
     */
    public const string TEXT = 'This report documents automated verification of control coverage. '
        . 'It does not constitute compliance certification.';

    /**
     * What the operator-responsibility section is, said once.
     */
    public const string CHECKLIST_NOTE = 'Controls below are discharged outside the software. '
        . 'Pulsar cannot observe them, does not count them toward coverage, and does not '
        . 'let them affect the exit code.';
}
