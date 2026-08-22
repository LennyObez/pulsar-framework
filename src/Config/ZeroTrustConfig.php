<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Policy\PolicyRule;
use Pulsar\Security\ZeroTrust\Privacy\SignalRetentionPolicy;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;

/**
 * Typed configuration DTO for the zero-trust module.
 *
 * Maps from the `zero_trust` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ZeroTrustConfig implements ReportsUnknownKeys
{
    /** Keys read from the `zero_trust` sub-array of config/security.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'default_min_confidence', 'continuous_verification_interval_seconds',
        'device_identity_required', 'step_up', 'retention_policies', 'signal_providers',
        'trust_score_threshold', 'rules',
    ];

    /**
     * @param bool $enabled Whether zero-trust evaluation is active
     * @param float $defaultMinConfidence Default minimum confidence for policy rules (0.0-1.0)
     * @param int $continuousVerificationIntervalSeconds How often to re-verify during active sessions
     * @param bool $deviceIdentityRequired Whether device identity verification is mandatory
     * @param StepUpConfig $stepUp Step-up authentication limits
     * @param list<SignalRetentionPolicy> $retentionPolicies Per-signal retention policies
     * @param list<string> $signalProviders Registered signal provider class names
     * @param float $trustScoreThreshold Minimum trust score to allow access (0.0-1.0)
     * @param list<PolicyRule> $rules Policy rules the engine evaluates (deny-by-default: with no rules,
     *        an enabled zero-trust route denies every request)
     * @param list<string> $unknownKeys Keys present in the raw `zero_trust` array that
     *        this DTO does not read, including those of the nested `step_up` section.
     *        Zero-trust is deny-by-default, so a misspelled key here fails closed
     *        rather than open — but it still silently discards the operator's intent.
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
        public array $rules = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
     *     rules?: list<array<string, mixed>>,
     * } $data Raw `zero_trust` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $stepUpData = $data['step_up'] ?? null;
        if (!is_array($stepUpData)) {
            $stepUpData = [];
        }

        $rawRetention = $data['retention_policies'] ?? null;
        $retentionPolicies = is_array($rawRetention)
            ? array_values(array_filter($rawRetention, is_array(...)))
            : [];

        $rawRules = $data['rules'] ?? null;
        $ruleArrays = is_array($rawRules)
            ? array_values(array_filter($rawRules, is_array(...)))
            : [];

        $stepUp = StepUpConfig::fromArray($stepUpData);

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            defaultMinConfidence: Coerce::float($data['default_min_confidence'] ?? null, 0.7),
            continuousVerificationIntervalSeconds: Coerce::integerLike($data['continuous_verification_interval_seconds'] ?? null, 300),
            deviceIdentityRequired: Coerce::strictBool($data['device_identity_required'] ?? null),
            stepUp: $stepUp,
            retentionPolicies: array_map(
                static fn(array $item): SignalRetentionPolicy => SignalRetentionPolicy::fromArray($item),
                $retentionPolicies,
            ),
            signalProviders: Coerce::listOfString($data['signal_providers'] ?? null),
            trustScoreThreshold: Coerce::float($data['trust_score_threshold'] ?? null, 0.6),
            rules: array_map(
                static fn(array $item): PolicyRule => PolicyRule::fromArray($item),
                $ruleArrays,
            ),
            unknownKeys: [
                ...UnknownKeys::collect($data, self::KNOWN_KEYS),
                ...UnknownKeys::nested('step_up', $stepUp),
            ],
        );
    }
}
