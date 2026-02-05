<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Pulsar\Api\Internal;

/**
 * Persistence contract for admin action history.
 */
#[Internal]
interface ActionHistoryStoreInterface
{
    public function record(ActionHistoryEntry $entry): void;

    /**
     * @return list<ActionHistoryEntry>
     */
    public function recent(int $limit = 50): array;

    /**
     * @return list<ActionHistoryEntry>
     */
    public function forResource(string $resourceName, int $limit = 50): array;
}
