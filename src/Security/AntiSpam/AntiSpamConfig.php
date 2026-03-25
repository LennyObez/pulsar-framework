<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

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
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            honeypotEnabled: $data['honeypot_enabled'] ?? true,
            honeypotFieldName: $data['honeypot_field_name'] ?? 'website_url',
            duplicateDetectionEnabled: $data['duplicate_detection_enabled'] ?? true,
            duplicateWindowSeconds: $data['duplicate_window_seconds'] ?? 300,
            duplicateSimilarityThreshold: $data['duplicate_similarity_threshold'] ?? 85.0,
            linkDensityEnabled: $data['link_density_enabled'] ?? true,
            maxLinkDensity: $data['max_link_density'] ?? 0.3,
            contentQualityEnabled: $data['content_quality_enabled'] ?? true,
            minContentLength: $data['min_content_length'] ?? 10,
            maxUppercaseRatio: $data['max_uppercase_ratio'] ?? 0.8,
            maxRepeatedCharRatio: $data['max_repeated_char_ratio'] ?? 0.5,
            proofOfWorkEnabled: $data['proof_of_work_enabled'] ?? false,
            proofOfWorkPrefix: $data['proof_of_work_prefix'] ?? '0000',
            captchaEnabled: $data['captcha_enabled'] ?? false,
            captchaProvider: $data['captcha_provider'] ?? 'hcaptcha',
            captchaSiteKey: $data['captcha_site_key'] ?? '',
            captchaSecretKey: $data['captcha_secret_key'] ?? '',
            accountAgeGateEnabled: $data['account_age_gate_enabled'] ?? false,
            minAccountAgeSeconds: $data['min_account_age_seconds'] ?? 300,
            reputationCooldownEnabled: $data['reputation_cooldown_enabled'] ?? true,
            cooldownTiers: $data['cooldown_tiers'] ?? ['new' => 60, 'established' => 10, 'moderator' => 0],
            shortCircuit: $data['short_circuit'] ?? false,
        );
    }
}
