<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio\Dto;

use DateTimeImmutable;
use Pulsar\Api\Internal;

/**
 * Row in the CMS audit panel: a single CMS-relevant audit event.
 */
#[Internal]
final readonly class AuditPanelEntry
{
    public function __construct(
        public string $id,
        public string $eventType,
        public string $outcome,
        public string $actor,
        public string $action,
        public string $resource,
        public DateTimeImmutable $timestamp,
        public string $evidenceHash,
    ) {}
}
