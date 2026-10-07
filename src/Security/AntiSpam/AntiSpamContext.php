<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Immutable context passed through the anti-spam pipeline.
 *
 * Carries all information checks need: the submission body, metadata,
 * and user identity information for reputation-based decisions.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamContext
{
    /**
     * @param string $body The submitted content body
     * @param string $ipHash Hashed IP address of the submitter, for internal
     *        reputation/rate-limiting (never sent to third parties)
     * @param string|null $userId Authenticated user ID (null for anonymous)
     * @param int|null $accountAgeSeconds Account age in seconds (null for anonymous)
     * @param string $reputationTier User reputation tier: 'new', 'established', 'moderator'
     * @param list<string> $recentBodies Recent submission bodies for duplicate detection
     * @param array<string, mixed> $formFields All form field values (for honeypot detection)
     * @param string|null $captchaToken CAPTCHA response token. For the self-hosted
     *        managed challenge this is the solved, signed, single-use proof-of-work
     *        token; {@see \Pulsar\Security\AntiSpam\CaptchaVerifierInterface} verifies it.
     * @param int $submissionTimestamp Unix timestamp of submission
     * @param string $formId Identifier of the form/route being submitted. The
     *        time-trap check requires a stamp minted for this exact form, so an
     *        integrator must set the same value here that it passed to the
     *        renderer. Defaults to '' (no form binding; timing still enforced).
     * @param string|null $ip Real client IP address. Used only by external CAPTCHA
     *        verifiers (hCaptcha/Turnstile) that require the genuine client IP for
     *        their server-side risk scoring; a hash would defeat that. Null when
     *        unavailable. Internal checks use $ipHash, not this.
     * @param string|null $email The sender's e-mail address, for the e-mail-domain
     *        check. Set it explicitly, or leave null to let {@see self::email()}
     *        fall back to the conventional 'email' form field.
     */
    public function __construct(
        public string $body,
        public string $ipHash,
        public ?string $userId = null,
        public ?int $accountAgeSeconds = null,
        public string $reputationTier = 'new',
        public array $recentBodies = [],
        public array $formFields = [],
        public ?string $captchaToken = null,
        public int $submissionTimestamp = 0,
        public string $formId = '',
        public ?string $ip = null,
        public ?string $email = null,
    ) {}

    #[NoDiscard]
    public function isAnonymous(): bool
    {
        return $this->userId === null;
    }

    /**
     * The sender's e-mail address for domain-level checks: the explicitly-set
     * $email, or the conventional 'email' form field when that is unset. Null
     * when neither is present.
     */
    #[NoDiscard]
    public function email(): ?string
    {
        if ($this->email !== null && $this->email !== '') {
            return $this->email;
        }

        /** @var mixed $field */
        $field = $this->formFields['email'] ?? null;

        return is_string($field) && $field !== '' ? $field : null;
    }
}
