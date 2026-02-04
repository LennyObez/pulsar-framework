<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Redaction;

use Pulsar\Api\Internal;

/**
 * Contract for redacting sensitive data from event payloads.
 */
#[Internal]
interface RedactionPolicyInterface
{
    /**
     * Redact sensitive data from a payload array.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array;
}
