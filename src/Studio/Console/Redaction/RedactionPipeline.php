<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Redaction;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\EventType;

/**
 * Chains multiple redaction policies and applies them per event type.
 *
 * The pipeline applies all registered policies in order. Additional
 * per-event-type policies can be registered for targeted redaction.
 */
#[Internal]
final class RedactionPipeline implements RedactionPipelineInterface
{
    /** @var list<RedactionPolicyInterface> */
    private array $globalPolicies = [];

    /** @var array<string, list<RedactionPolicyInterface>> */
    private array $typePolicies = [];

    /**
     * Add a policy that applies to all event types.
     */
    public function addGlobalPolicy(RedactionPolicyInterface $policy): void
    {
        $this->globalPolicies[] = $policy;
    }

    /**
     * Add a policy that applies only to a specific event type.
     */
    public function addTypePolicy(EventType $type, RedactionPolicyInterface $policy): void
    {
        $this->typePolicies[$type->value] ??= [];
        $this->typePolicies[$type->value][] = $policy;
    }

    /**
     * Apply all applicable policies to a payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    #[Override]
    public function redact(array $payload, EventType $eventType): array
    {
        // Apply global policies first
        foreach ($this->globalPolicies as $policy) {
            $payload = $policy->redact($payload);
        }

        // Apply type-specific policies
        if (isset($this->typePolicies[$eventType->value])) {
            foreach ($this->typePolicies[$eventType->value] as $policy) {
                $payload = $policy->redact($payload);
            }
        }

        return $payload;
    }

    /**
     * Create a pipeline with the default redaction policy.
     */
    #[NoDiscard]
    public static function withDefaults(): self
    {
        $pipeline = new self();
        $pipeline->addGlobalPolicy(new DefaultRedactionPolicy());

        return $pipeline;
    }
}
