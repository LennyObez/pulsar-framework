<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use Pulsar\Api\Api;

/**
 * What the egress guard did with one outbound call.
 *
 * Distinct from {@see \Pulsar\Security\Dlp\DlpAction}, which is the policy the
 * operator configured. This is the observed result of applying it — the two are
 * not the same fact and a deployment configured to redact reports
 * {@see Redacted} only on the calls where something was actually rewritten.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum AiEgressOutcome: string
{
    /** The payload was sent as the caller wrote it. */
    case Allowed = 'allowed';

    /**
     * The payload was sent with classified spans masked.
     *
     * The model was asked a different question from the one the caller wrote,
     * which is why this outcome is reported rather than merely logged.
     */
    case Redacted = 'redacted';

    /** Nothing was sent. The call raised {@see \Pulsar\AI\Exception\AiEgressRefusedException}. */
    case Refused = 'refused';
}
