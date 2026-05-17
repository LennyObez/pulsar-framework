<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Portability;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Represents a data portability export request.
 *
 * Tracks the lifecycle from submission through fulfillment or cancellation
 * as required by Data Act Article 5 (right to data portability).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ExportRequest
{
    /**
     * @param string             $id          Unique request identifier
     * @param string             $userId      User who requested the export
     * @param string             $format      Requested export format
     * @param list<string>       $scopes      Data scopes included in the export
     * @param ExportStatus       $status      Current status of the request
     * @param DateTimeImmutable  $requestedAt When the request was submitted
     * @param DateTimeImmutable  $deadline    Deadline for fulfillment
     * @param string|null        $dataPath    Path to exported data (when fulfilled)
     * @param DateTimeImmutable|null $fulfilledAt When the request was fulfilled
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $format,
        public array $scopes,
        public ExportStatus $status,
        public DateTimeImmutable $requestedAt,
        public DateTimeImmutable $deadline,
        public ?string $dataPath = null,
        public ?DateTimeImmutable $fulfilledAt = null,
    ) {}

    /**
     * Whether this request is still pending fulfillment.
     */
    #[NoDiscard]
    public function isPending(): bool
    {
        return $this->status === ExportStatus::Pending;
    }

    /**
     * Whether this request has been fulfilled.
     */
    #[NoDiscard]
    public function isFulfilled(): bool
    {
        return $this->status === ExportStatus::Fulfilled;
    }

    /**
     * Whether this request is overdue (past its deadline and not yet fulfilled).
     */
    #[NoDiscard]
    public function isOverdue(): bool
    {
        return $this->isPending() && new DateTimeImmutable() > $this->deadline;
    }
}
