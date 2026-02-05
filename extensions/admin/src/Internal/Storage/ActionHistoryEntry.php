<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Pulsar\Api\Internal;

/**
 * A single entry in the admin action history log.
 */
#[Internal]
final readonly class ActionHistoryEntry
{
    public function __construct(
        public string $id,
        public string $action,
        public string $resourceName,
        public ?string $recordId,
        public string $actor,
        public int $timestamp,
        public bool $success,
        public string $detail = '',
    ) {}
}
