<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawCooldownTiers = $data['cooldown_tiers'] ?? null;
        /** @var array<string, int> $cooldownTiers */
        $cooldownTiers = is_array($rawCooldownTiers) ? $rawCooldownTiers : ['new' => 60, 'established' => 10, 'moderator' => 0];

        $rawHoneypotEnabled = $data['honeypot_enabled'] ?? null;
        $rawHoneypotFieldName = $data['honeypot_field_name'] ?? null;
        $rawDuplicateDetection = $data['duplicate_detection_enabled'] ?? null;
        $rawDuplicateWindow = $data['duplicate_window_seconds'] ?? null;
        $rawDuplicateSimilarity = $data['duplicate_similarity_threshold'] ?? null;
        $rawLinkDensity = $data['link_density_enabled'] ?? null;
        $rawMaxLinkDensity = $data['max_link_density'] ?? null;
        $rawContentQuality = $data['content_quality_enabled'] ?? null;
        $rawMinContent = $data['min_content_length'] ?? null;
        $rawMaxUppercase = $data['max_uppercase_ratio'] ?? null;
        $rawMaxRepeatedChar = $data['max_repeated_char_ratio'] ?? null;
        $rawProofOfWorkEnabled = $data['proof_of_work_enabled'] ?? null;
        $rawProofOfWorkPrefix = $data['proof_of_work_prefix'] ?? null;
        $rawCaptchaEnabled = $data['captcha_enabled'] ?? null;
        $rawCaptchaProvider = $data['captcha_provider'] ?? null;
        $rawCaptchaSiteKey = $data['captcha_site_key'] ?? null;
        $rawCaptchaSecretKey = $data['captcha_secret_key'] ?? null;
        $rawAccountAgeGate = $data['account_age_gate_enabled'] ?? null;
        $rawMinAccountAge = $data['min_account_age_seconds'] ?? null;
        $rawReputationCooldown = $data['reputation_cooldown_enabled'] ?? null;
        $rawShortCircuit = $data['short_circuit'] ?? null;

        return new self(
            honeypotEnabled: is_bool($rawHoneypotEnabled) ? $rawHoneypotEnabled : true,
            honeypotFieldName: is_string($rawHoneypotFieldName) ? $rawHoneypotFieldName : 'website_url',
            duplicateDetectionEnabled: is_bool($rawDuplicateDetection) ? $rawDuplicateDetection : true,
            duplicateWindowSeconds: is_int($rawDuplicateWindow) ? $rawDuplicateWindow : 300,
            duplicateSimilarityThreshold: is_float($rawDuplicateSimilarity) ? $rawDuplicateSimilarity : 85.0,
            linkDensityEnabled: is_bool($rawLinkDensity) ? $rawLinkDensity : true,
            maxLinkDensity: is_float($rawMaxLinkDensity) ? $rawMaxLinkDensity : 0.3,
            contentQualityEnabled: is_bool($rawContentQuality) ? $rawContentQuality : true,
            minContentLength: is_int($rawMinContent) ? $rawMinContent : 10,
            maxUppercaseRatio: is_float($rawMaxUppercase) ? $rawMaxUppercase : 0.8,
            maxRepeatedCharRatio: is_float($rawMaxRepeatedChar) ? $rawMaxRepeatedChar : 0.5,
            proofOfWorkEnabled: is_bool($rawProofOfWorkEnabled) ? $rawProofOfWorkEnabled : false,
            proofOfWorkPrefix: is_string($rawProofOfWorkPrefix) ? $rawProofOfWorkPrefix : '0000',
            captchaEnabled: is_bool($rawCaptchaEnabled) ? $rawCaptchaEnabled : false,
            captchaProvider: is_string($rawCaptchaProvider) ? $rawCaptchaProvider : 'hcaptcha',
            captchaSiteKey: is_string($rawCaptchaSiteKey) ? $rawCaptchaSiteKey : '',
            captchaSecretKey: is_string($rawCaptchaSecretKey) ? $rawCaptchaSecretKey : '',
            accountAgeGateEnabled: is_bool($rawAccountAgeGate) ? $rawAccountAgeGate : false,
            minAccountAgeSeconds: is_int($rawMinAccountAge) ? $rawMinAccountAge : 300,
            reputationCooldownEnabled: is_bool($rawReputationCooldown) ? $rawReputationCooldown : true,
            cooldownTiers: $cooldownTiers,
            shortCircuit: is_bool($rawShortCircuit) ? $rawShortCircuit : false,
        );
    }
}
