<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use Pulsar\Api\Api;

/**
 * What a standalone time-trap gate does when a submission is filled implausibly
 * fast for a human (a bot posting on page load).
 *
 * Only the timing signal is policy-driven; every other case (missing, malformed,
 * tampered, wrong-form, future-dated, or stale stamp) always fails open so a slow
 * human never loses a submission ("zero lost lead").
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum TimeTrapFailurePolicy: string
{
    /**
     * Treat a too-fast submit as a silent success: accept the request, show the
     * normal success response, but drop the payload. The bot is told nothing —
     * the deliberate "send nothing, reveal nothing" posture. {@see TimeTrapGuard}
     * returns {@see TimeTrapDecision::SilentlyDrop}.
     */
    case SilentAccept = 'silent_accept';

    /** Reject a too-fast submit outright (a visible failure, e.g. HTTP 422). */
    case HardReject = 'hard_reject';

    /**
     * Do not block on a too-fast submit; the signal is advisory only (the
     * pipeline adds it to the aggregate spam score). This is the fail-open
     * default, preserving the zero-lost-lead posture.
     */
    case ScoreOnly = 'score_only';
}
