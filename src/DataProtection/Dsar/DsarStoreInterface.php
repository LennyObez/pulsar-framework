<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use Pulsar\Api\Api;

/**
 * Persistence contract for DSAR requests.
 * @api
 */
#[Api(since: '1.0.0')]
interface DsarStoreInterface
{
    public function save(DsarRequest $request): void;

    public function findById(string $id): ?DsarRequest;

    public function findBySubject(string $subjectId): ?DsarRequest;

    /** @return list<DsarRequest> */
    public function findAll(): array;
}
