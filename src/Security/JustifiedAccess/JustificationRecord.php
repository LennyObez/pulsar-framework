<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

use function is_array;
use function is_bool;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $supervisorApproval = $data['supervisor_approval'] ?? null;

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            actorId: is_string($data['actor_id'] ?? null) ? $data['actor_id'] : '',
            actorName: is_string($data['actor_name'] ?? null) ? $data['actor_name'] : '',
            actorRole: is_string($data['actor_role'] ?? null) ? $data['actor_role'] : '',
            resourceType: is_string($data['resource_type'] ?? null) ? $data['resource_type'] : '',
            resourceId: is_string($data['resource_id'] ?? null) ? $data['resource_id'] : '',
            category: JustificationCategory::from(is_string($data['category'] ?? null) ? $data['category'] : 'customer_request'),
            justificationText: is_string($data['justification_text'] ?? null) ? $data['justification_text'] : '',
            dataClassification: DataClassification::from(is_string($data['data_classification'] ?? null) ? $data['data_classification'] : 'internal'),
            accessTimestamp: is_string($data['access_timestamp'] ?? null) ? new DateTimeImmutable($data['access_timestamp']) : new DateTimeImmutable(),
            sessionId: is_string($data['session_id'] ?? null) ? $data['session_id'] : '',
            ipAddress: is_string($data['ip_address'] ?? null) ? $data['ip_address'] : '',
            supervisorApproval: is_bool($supervisorApproval) ? $supervisorApproval : null,
            reviewStatus: ReviewStatus::from(is_string($data['review_status'] ?? null) ? $data['review_status'] : 'pending'),
            breakTheGlass: is_bool($data['break_the_glass'] ?? null) && $data['break_the_glass'],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
