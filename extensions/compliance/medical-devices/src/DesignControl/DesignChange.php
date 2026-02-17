<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\DesignControl;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A design change record per ISO 13485 Section 7.3.9.
 *
 * Design changes must be identified, documented, reviewed, verified,
 * validated, and approved before implementation.
 */
#[Api(since: '1.0.0')]
final readonly class DesignChange
{
    public function __construct(
        public string $changeId,
        public string $description,
        public string $justification,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $approvedAt = null,
        public ?string $approvedBy = null,
        public ?string $impactAssessment = null,
        public bool $requiresRevalidation = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'change_id' => $this->changeId,
            'description' => $this->description,
            'justification' => $this->justification,
            'requested_at' => $this->requestedAt->format('Y-m-d'),
            'requires_revalidation' => $this->requiresRevalidation,
        ];

        if ($this->approvedAt !== null) {
            $data['approved_at'] = $this->approvedAt->format('Y-m-d');
        }

        if ($this->approvedBy !== null) {
            $data['approved_by'] = $this->approvedBy;
        }

        if ($this->impactAssessment !== null) {
            $data['impact_assessment'] = $this->impactAssessment;
        }

        return $data;
    }
}
