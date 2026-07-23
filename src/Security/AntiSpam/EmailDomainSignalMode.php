<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Api;

use function is_string;
use function strtolower;
use function trim;

/**
 * How an e-mail-domain signal (disposable, MX deliverability) participates in
 * the anti-spam decision.
 *
 * - Hard  : a positive signal fails the check (AntiSpamResult::passed = false),
 *           so a controller reading the result blocks the submission.
 * - Score : a positive signal contributes to the aggregate spam score without
 *           failing the check, leaving the block decision to the controller's
 *           threshold — the "zero lost lead" posture.
 * - Off   : the signal is not evaluated.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum EmailDomainSignalMode: string
{
    case Hard = 'hard';
    case Score = 'score';
    case Off = 'off';

    /**
     * Parse a configured value, falling back to $default for anything
     * unrecognised (including null / non-string).
     */
    public static function fromValue(mixed $value, self $default = self::Off): self
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';

        return match ($normalized) {
            'hard' => self::Hard,
            'score' => self::Score,
            'off' => self::Off,
            default => $default,
        };
    }
}
