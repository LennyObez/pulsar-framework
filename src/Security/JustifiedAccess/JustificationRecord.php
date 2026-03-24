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
        $rawId = $data['id'] ?? null;
        $rawActorId = $data['actor_id'] ?? null;
        $rawActorName = $data['actor_name'] ?? null;
        $rawActorRole = $data['actor_role'] ?? null;
        $rawResourceType = $data['resource_type'] ?? null;
        $rawResourceId = $data['resource_id'] ?? null;
        $rawCategory = $data['category'] ?? null;
        $rawJustification = $data['justification_text'] ?? null;
        $rawClassification = $data['data_classification'] ?? null;
        $rawAccessTimestamp = $data['access_timestamp'] ?? null;
        $rawSessionId = $data['session_id'] ?? null;
        $rawIpAddress = $data['ip_address'] ?? null;
        $supervisorApproval = $data['supervisor_approval'] ?? null;
        $rawReviewStatus = $data['review_status'] ?? null;
        $rawBreakTheGlass = $data['break_the_glass'] ?? null;
        $rawMetadata = $data['metadata'] ?? null;

        return new self(
            id: is_string($rawId) ? $rawId : '',
            actorId: is_string($rawActorId) ? $rawActorId : '',
            actorName: is_string($rawActorName) ? $rawActorName : '',
            actorRole: is_string($rawActorRole) ? $rawActorRole : '',
            resourceType: is_string($rawResourceType) ? $rawResourceType : '',
            resourceId: is_string($rawResourceId) ? $rawResourceId : '',
            category: JustificationCategory::from(is_string($rawCategory) ? $rawCategory : 'customer_request'),
            justificationText: is_string($rawJustification) ? $rawJustification : '',
            dataClassification: DataClassification::from(is_string($rawClassification) ? $rawClassification : 'internal'),
            accessTimestamp: is_string($rawAccessTimestamp) ? new DateTimeImmutable($rawAccessTimestamp) : new DateTimeImmutable(),
            sessionId: is_string($rawSessionId) ? $rawSessionId : '',
            ipAddress: is_string($rawIpAddress) ? $rawIpAddress : '',
            supervisorApproval: is_bool($supervisorApproval) ? $supervisorApproval : null,
            reviewStatus: ReviewStatus::from(is_string($rawReviewStatus) ? $rawReviewStatus : 'pending'),
            breakTheGlass: is_bool($rawBreakTheGlass) && $rawBreakTheGlass,
            metadata: is_array($rawMetadata) ? $rawMetadata : [],
        );
    }
}
