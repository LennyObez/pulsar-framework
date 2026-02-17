<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable context passed through the anti-spam pipeline.
 *
 * Carries all information checks need: the submission body, metadata,
 * and user identity information for reputation-based decisions.
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamContext
{
    /**
     * @param string $body The submitted content body
     * @param string $ipHash Hashed IP address of the submitter
     * @param string|null $userId Authenticated user ID (null for anonymous)
     * @param int|null $accountAgeSeconds Account age in seconds (null for anonymous)
     * @param string $reputationTier User reputation tier: 'new', 'established', 'moderator'
     * @param list<string> $recentBodies Recent submission bodies for duplicate detection
     * @param array<string, mixed> $formFields All form field values (for honeypot detection)
     * @param string|null $powChallenge Proof-of-work challenge value
     * @param string|null $powNonce Proof-of-work nonce value
     * @param string|null $captchaToken CAPTCHA response token
     * @param int $submissionTimestamp Unix timestamp of submission
     */
    public function __construct(
        public string $body,
        public string $ipHash,
        public ?string $userId = null,
        public ?int $accountAgeSeconds = null,
        public string $reputationTier = 'new',
        public array $recentBodies = [],
        public array $formFields = [],
        public ?string $powChallenge = null,
        public ?string $powNonce = null,
        public ?string $captchaToken = null,
        public int $submissionTimestamp = 0,
    ) {}

    #[NoDiscard]
    public function isAnonymous(): bool
    {
        return $this->userId === null;
    }
}
