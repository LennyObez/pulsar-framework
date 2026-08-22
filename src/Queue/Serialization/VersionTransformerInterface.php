<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Api;

/**
 * Contract for payload version migration transformers.
 *
 * Each transformer handles migration of a job payload between specific
 * schema versions. Implementations must be pure (no side effects, no IO).
 * @api
 */
#[Api(since: '1.0.0')]
interface VersionTransformerInterface
{
    /**
     * Whether this transformer can migrate the given class between the specified versions.
     */
    public function supports(string $class, int $fromVersion, int $toVersion): bool;

    /**
     * Transform a payload from one schema version to another.
     *
     * @param array<string, mixed> $data        The payload data to transform.
     * @param int                  $fromVersion The source schema version.
     * @param int                  $toVersion   The target schema version.
     *
     * @return array<string, mixed> The transformed payload data.
     */
    public function transform(array $data, int $fromVersion, int $toVersion): array;
}
