<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Immutable record of a justified access event.
 *
 * Captures who accessed what, when, why, and from where. Forms the
 * core audit trail for purpose-bound data access required by PCI-DSS,
 * HIPAA, GDPR, PSD2, DORA, and SOC 2.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class JustificationRecord
{
    /**
     * @param array<mixed, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $actorId,
        public string $actorName,
        public string $actorRole,
        public string $resourceType,
        public string $resourceId,
        public JustificationCategory $category,
        public string $justificationText,
        public DataClassification $dataClassification,
        public DateTimeImmutable $accessTimestamp,
        public string $sessionId,
        public string $ipAddress,
        public ?bool $supervisorApproval,
        public ReviewStatus $reviewStatus,
        public bool $breakTheGlass = false,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'actor_role' => $this->actorRole,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'category' => $this->category->value,
            'justification_text' => $this->justificationText,
            'data_classification' => $this->dataClassification->value,
            'access_timestamp' => $this->accessTimestamp->format('Y-m-d\TH:i:s.uP'),
            'session_id' => $this->sessionId,
            'ip_address' => $this->ipAddress,
            'supervisor_approval' => $this->supervisorApproval,
            'review_status' => $this->reviewStatus->value,
            'break_the_glass' => $this->breakTheGlass,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array{
     *     id?: string,
     *     actor_id?: string,
     *     actor_name?: string,
     *     actor_role?: string,
     *     resource_type?: string,
     *     resource_id?: string,
     *     category?: string,
     *     justification_text?: string,
     *     data_classification?: string,
     *     access_timestamp?: string,
     *     session_id?: string,
     *     ip_address?: string,
     *     supervisor_approval?: bool|null,
     *     review_status?: string,
     *     break_the_glass?: bool,
     *     metadata?: array<mixed, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            actorId: $data['actor_id'] ?? '',
            actorName: $data['actor_name'] ?? '',
            actorRole: $data['actor_role'] ?? '',
            resourceType: $data['resource_type'] ?? '',
            resourceId: $data['resource_id'] ?? '',
            category: JustificationCategory::from($data['category'] ?? 'customer_request'),
            justificationText: $data['justification_text'] ?? '',
            dataClassification: DataClassification::from($data['data_classification'] ?? 'internal'),
            accessTimestamp: isset($data['access_timestamp'])
                ? new DateTimeImmutable($data['access_timestamp'])
                : new DateTimeImmutable(),
            sessionId: $data['session_id'] ?? '',
            ipAddress: $data['ip_address'] ?? '',
            supervisorApproval: $data['supervisor_approval'] ?? null,
            reviewStatus: ReviewStatus::from($data['review_status'] ?? 'pending'),
            breakTheGlass: $data['break_the_glass'] ?? false,
            metadata: $data['metadata'] ?? [],
        );
    }
}
