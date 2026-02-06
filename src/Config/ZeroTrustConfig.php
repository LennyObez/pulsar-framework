<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Privacy\SignalRetentionPolicy;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;

use function array_map;
use function array_values;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for the zero-trust module.
 *
 * Maps from the `zero_trust` key of `config/security.php`.
 */
#[Api(since: '1.0.0')]
readonly class ZeroTrustConfig
{
    /**
     * @param bool $enabled Whether zero-trust evaluation is active
     * @param float $defaultMinConfidence Default minimum confidence for policy rules (0.0-1.0)
     * @param int $continuousVerificationIntervalSeconds How often to re-verify during active sessions
     * @param bool $deviceIdentityRequired Whether device identity verification is mandatory
     * @param StepUpConfig $stepUp Step-up authentication limits
     * @param list<SignalRetentionPolicy> $retentionPolicies Per-signal retention policies
     * @param list<string> $signalProviders Registered signal provider class names
     * @param float $trustScoreThreshold Minimum trust score to allow access (0.0-1.0)
     */
    public function __construct(
        public bool $enabled = false,
        public float $defaultMinConfidence = 0.7,
        public int $continuousVerificationIntervalSeconds = 300,
        public bool $deviceIdentityRequired = false,
        public StepUpConfig $stepUp = new StepUpConfig(),
        public array $retentionPolicies = [],
        public array $signalProviders = [],
        public float $trustScoreThreshold = 0.6,
    ) {}

    /**
     * Build from the raw zero-trust config array.
     *
     * @param array<string, mixed> $data Raw `zero_trust` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $stepUpData */
        $stepUpData = is_array($data['step_up'] ?? null) ? $data['step_up'] : [];

        /** @var list<array<string, mixed>> $retentionData */
        $retentionData = is_array($data['retention_policies'] ?? null) ? $data['retention_policies'] : [];

        /** @var list<string> $providers */
        $providers = is_array($data['signal_providers'] ?? null)
            ? array_values(array_filter($data['signal_providers'], is_string(...)))
            : [];

        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : false,
            defaultMinConfidence: is_float($data['default_min_confidence'] ?? null)
                ? $data['default_min_confidence']
                : (is_int($data['default_min_confidence'] ?? null) ? (float) $data['default_min_confidence'] : 0.7),
            continuousVerificationIntervalSeconds: is_int($data['continuous_verification_interval_seconds'] ?? null)
                ? $data['continuous_verification_interval_seconds']
                : 300,
            deviceIdentityRequired: is_bool($data['device_identity_required'] ?? null)
                ? $data['device_identity_required']
                : false,
            stepUp: StepUpConfig::fromArray($stepUpData),
            retentionPolicies: array_map(
                static fn(array $item): SignalRetentionPolicy => SignalRetentionPolicy::fromArray($item),
                array_values(array_filter($retentionData, is_array(...))),
            ),
            signalProviders: $providers,
            trustScoreThreshold: is_float($data['trust_score_threshold'] ?? null)
                ? $data['trust_score_threshold']
                : (is_int($data['trust_score_threshold'] ?? null) ? (float) $data['trust_score_threshold'] : 0.6),
        );
    }
}
