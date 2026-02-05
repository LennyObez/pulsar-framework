<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Domain\SavedView;

/**
 * Persistence contract for admin saved views.
 */
#[Internal]
interface SavedViewStoreInterface
{
    /**
     * @return list<SavedView>
     */
    public function listForResource(string $resourceName): array;

    public function find(string $id): ?SavedView;

    public function save(SavedView $view): void;

    public function delete(string $id): void;
}
