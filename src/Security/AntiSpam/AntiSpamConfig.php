<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Configuration DTO for the anti-spam pipeline.
 *
 * Controls which checks are enabled and their thresholds.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamConfig
{
    /**
     * @param bool $honeypotEnabled Enable honeypot field detection
     * @param string $honeypotFieldName Hidden field name used for honeypot
     * @param bool $duplicateDetectionEnabled Enable duplicate submission detection
     * @param int $duplicateWindowSeconds Time window for duplicate detection
     * @param float $duplicateSimilarityThreshold Percentage similarity to flag (0.0–100.0)
     * @param bool $linkDensityEnabled Enable link density checking
     * @param float $maxLinkDensity Maximum ratio of URL chars to total chars (0.0–1.0)
     * @param bool $contentQualityEnabled Enable content quality gate
     * @param int $minContentLength Minimum body length in characters
     * @param float $maxUppercaseRatio Maximum ratio of uppercase characters (0.0–1.0)
     * @param float $maxRepeatedCharRatio Maximum ratio of repeated characters (0.0–1.0)
     * @param bool $proofOfWorkEnabled Enable proof-of-work verification
     * @param string $proofOfWorkPrefix SHA-256 hash prefix requirement
     * @param bool $captchaEnabled Enable CAPTCHA verification
     * @param string $captchaProvider CAPTCHA provider: 'hcaptcha' or 'turnstile'
     * @param string $captchaSiteKey CAPTCHA site key
     * @param string $captchaSecretKey CAPTCHA secret key
     * @param bool $accountAgeGateEnabled Enable account age gate
     * @param int $minAccountAgeSeconds Minimum account age in seconds
     * @param bool $reputationCooldownEnabled Enable reputation-based cooldowns
     * @param array<string, int> $cooldownTiers Cooldown seconds per reputation tier
     * @param bool $shortCircuit Stop on first failure instead of running all checks
     * @param int $managedChallengeBits Proof-of-work difficulty (leading zero bits) for the self-hosted 'managed' captcha provider
     * @param int $managedChallengeTtlSeconds Lifetime of an issued managed challenge
     * @param string $managedChallengeFieldName Form field name the managed-challenge widget writes its solved token into
     * @param bool $timeTrapEnabled Enable the no-JS form-fill-timing check (opt-in)
     * @param int $timeTrapMinSeconds Minimum plausible human fill time in seconds (faster ⇒ flagged)
     * @param int $timeTrapMaxSeconds Maximum stamp age in seconds before a page is treated as stale
     * @param string $timeTrapFieldName Hidden field name carrying the signed render timestamp
     * @param bool $behaviorEnabled Enable the self-hosted behavioural-signals score-only check (opt-in)
     * @param string $behaviorFieldName Hidden field name carrying the client behavioural blob
     * @param array<string, int|float> $behaviorWeights HeuristicScorer weight overrides (see HeuristicScorer::fromWeights)
     */
    public function __construct(
        public bool $honeypotEnabled = true,
        public string $honeypotFieldName = 'website_url',
        public bool $duplicateDetectionEnabled = true,
        public int $duplicateWindowSeconds = 300,
        public float $duplicateSimilarityThreshold = 85.0,
        public bool $linkDensityEnabled = true,
        public float $maxLinkDensity = 0.3,
        public bool $contentQualityEnabled = true,
        public int $minContentLength = 10,
        public float $maxUppercaseRatio = 0.8,
        public float $maxRepeatedCharRatio = 0.5,
        public bool $proofOfWorkEnabled = false,
        public string $proofOfWorkPrefix = '0000',
        public bool $captchaEnabled = false,
        public string $captchaProvider = 'hcaptcha',
        public string $captchaSiteKey = '',
        public string $captchaSecretKey = '',
        public bool $accountAgeGateEnabled = false,
        public int $minAccountAgeSeconds = 300,
        public bool $reputationCooldownEnabled = true,
        public array $cooldownTiers = ['new' => 60, 'established' => 10, 'moderator' => 0],
        public bool $shortCircuit = false,
        public int $managedChallengeBits = 16,
        public int $managedChallengeTtlSeconds = 300,
        public string $managedChallengeFieldName = 'pulsar-challenge-response',
        public bool $timeTrapEnabled = false,
        public int $timeTrapMinSeconds = 3,
        public int $timeTrapMaxSeconds = 3600,
        public string $timeTrapFieldName = 'pulsar-form-ts',
        public bool $behaviorEnabled = false,
        public string $behaviorFieldName = 'pulsar-bx',
        public array $behaviorWeights = [],
    ) {}

    /**
     * @param array{
     *     honeypot_enabled?: bool,
     *     honeypot_field_name?: string,
     *     duplicate_detection_enabled?: bool,
     *     duplicate_window_seconds?: int,
     *     duplicate_similarity_threshold?: float,
     *     link_density_enabled?: bool,
     *     max_link_density?: float,
     *     content_quality_enabled?: bool,
     *     min_content_length?: int,
     *     max_uppercase_ratio?: float,
     *     max_repeated_char_ratio?: float,
     *     proof_of_work_enabled?: bool,
     *     proof_of_work_prefix?: string,
     *     captcha_enabled?: bool,
     *     captcha_provider?: string,
     *     captcha_site_key?: string,
     *     captcha_secret_key?: string,
     *     account_age_gate_enabled?: bool,
     *     min_account_age_seconds?: int,
     *     reputation_cooldown_enabled?: bool,
     *     cooldown_tiers?: array<string, int>,
     *     short_circuit?: bool,
     *     managed_challenge_bits?: int,
     *     managed_challenge_ttl_seconds?: int,
     *     managed_challenge_field_name?: string,
     *     time_trap_enabled?: bool,
     *     time_trap_min_seconds?: int,
     *     time_trap_max_seconds?: int,
     *     time_trap_field_name?: string,
     *     behavior_enabled?: bool,
     *     behavior_field_name?: string,
     *     behavior_weights?: array<string, int|float>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $cooldownTiers = $data['cooldown_tiers'] ?? null;
        $behaviorWeights = $data['behavior_weights'] ?? null;

        return new self(
            honeypotEnabled: Coerce::strictBool($data['honeypot_enabled'] ?? null, true),
            honeypotFieldName: Coerce::string($data['honeypot_field_name'] ?? null, 'website_url'),
            duplicateDetectionEnabled: Coerce::strictBool($data['duplicate_detection_enabled'] ?? null, true),
            duplicateWindowSeconds: Coerce::int($data['duplicate_window_seconds'] ?? null, 300),
            duplicateSimilarityThreshold: Coerce::float($data['duplicate_similarity_threshold'] ?? null, 85.0),
            linkDensityEnabled: Coerce::strictBool($data['link_density_enabled'] ?? null, true),
            maxLinkDensity: Coerce::float($data['max_link_density'] ?? null, 0.3),
            contentQualityEnabled: Coerce::strictBool($data['content_quality_enabled'] ?? null, true),
            minContentLength: Coerce::int($data['min_content_length'] ?? null, 10),
            maxUppercaseRatio: Coerce::float($data['max_uppercase_ratio'] ?? null, 0.8),
            maxRepeatedCharRatio: Coerce::float($data['max_repeated_char_ratio'] ?? null, 0.5),
            proofOfWorkEnabled: Coerce::strictBool($data['proof_of_work_enabled'] ?? null),
            proofOfWorkPrefix: Coerce::string($data['proof_of_work_prefix'] ?? null, '0000'),
            captchaEnabled: Coerce::strictBool($data['captcha_enabled'] ?? null),
            captchaProvider: Coerce::string($data['captcha_provider'] ?? null, 'hcaptcha'),
            captchaSiteKey: Coerce::string($data['captcha_site_key'] ?? null),
            captchaSecretKey: Coerce::string($data['captcha_secret_key'] ?? null),
            accountAgeGateEnabled: Coerce::strictBool($data['account_age_gate_enabled'] ?? null),
            minAccountAgeSeconds: Coerce::int($data['min_account_age_seconds'] ?? null, 300),
            reputationCooldownEnabled: Coerce::strictBool($data['reputation_cooldown_enabled'] ?? null, true),
            cooldownTiers: is_array($cooldownTiers) ? $cooldownTiers : ['new' => 60, 'established' => 10, 'moderator' => 0],
            shortCircuit: Coerce::strictBool($data['short_circuit'] ?? null),
            managedChallengeBits: Coerce::int($data['managed_challenge_bits'] ?? null, 16),
            managedChallengeTtlSeconds: Coerce::int($data['managed_challenge_ttl_seconds'] ?? null, 300),
            managedChallengeFieldName: Coerce::string($data['managed_challenge_field_name'] ?? null, 'pulsar-challenge-response'),
            timeTrapEnabled: Coerce::strictBool($data['time_trap_enabled'] ?? null),
            timeTrapMinSeconds: Coerce::int($data['time_trap_min_seconds'] ?? null, 3),
            timeTrapMaxSeconds: Coerce::int($data['time_trap_max_seconds'] ?? null, 3600),
            timeTrapFieldName: Coerce::string($data['time_trap_field_name'] ?? null, 'pulsar-form-ts'),
            behaviorEnabled: Coerce::strictBool($data['behavior_enabled'] ?? null),
            behaviorFieldName: Coerce::string($data['behavior_field_name'] ?? null, 'pulsar-bx'),
            behaviorWeights: is_array($behaviorWeights) ? $behaviorWeights : [],
        );
    }
}
