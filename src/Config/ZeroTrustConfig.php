<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Privacy\SignalRetentionPolicy;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function is_string;

/**
 * Typed configuration DTO for the zero-trust module.
 *
 * Maps from the `zero_trust` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ZeroTrustConfig
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
     * @param array{
     *     enabled?: bool,
     *     default_min_confidence?: float|int,
     *     continuous_verification_interval_seconds?: int,
     *     device_identity_required?: bool,
     *     step_up?: array<string, mixed>,
     *     retention_policies?: list<array<string, mixed>>,
     *     signal_providers?: list<string>,
     *     trust_score_threshold?: float|int,
     * } $data Raw `zero_trust` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $stepUpData */
        $stepUpData = $data['step_up'] ?? [];

        $providers = array_values(array_filter($data['signal_providers'] ?? [], is_string(...)));

        return new self(
            enabled: $data['enabled'] ?? false,
            defaultMinConfidence: (float) ($data['default_min_confidence'] ?? 0.7),
            continuousVerificationIntervalSeconds: $data['continuous_verification_interval_seconds'] ?? 300,
            deviceIdentityRequired: $data['device_identity_required'] ?? false,
            stepUp: StepUpConfig::fromArray($stepUpData),
            retentionPolicies: array_map(
                static fn(array $item): SignalRetentionPolicy => SignalRetentionPolicy::fromArray($item),
                array_values(array_filter($data['retention_policies'] ?? [], is_array(...))),
            ),
            signalProviders: $providers,
            trustScoreThreshold: (float) ($data['trust_score_threshold'] ?? 0.6),
        );
    }
}
