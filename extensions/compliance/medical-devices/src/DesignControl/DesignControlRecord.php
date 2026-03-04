<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\DesignControl;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Design control record per ISO 13485 Section 7.3.
 *
 * Tracks design inputs, outputs, verification, and validation activities
 * throughout the design and development lifecycle.
 *
 * NOTE: ISO 13485 design control requires organizational processes beyond
 * what a framework can enforce. This record provides tooling support for
 * documenting and tracking design control activities.
 *
 * @see ISO 13485:2016 Section 7.3
 */
#[Api(since: '1.0.0')]
final readonly class DesignControlRecord
{
    /**
     * @param list<string> $designInputs      Requirements and specifications (7.3.3)
     * @param list<string> $designOutputs     Design results meeting input requirements (7.3.4)
     * @param list<string> $verificationResults Results confirming outputs meet inputs (7.3.5)
     * @param list<string> $validationResults  Results confirming device meets user needs (7.3.6)
     * @param list<string> $reviewNotes        Design review records (7.3.2)
     * @param list<DesignChange> $changes      Design change records (7.3.9)
     */
    public function __construct(
        public string $id,
        public string $deviceIdentifier,
        public string $projectName,
        public DesignPhase $phase,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
        public array $designInputs = [],
        public array $designOutputs = [],
        public array $verificationResults = [],
        public array $validationResults = [],
        public array $reviewNotes = [],
        public array $changes = [],
        public ?string $designTransferNotes = null,
    ) {}

    /**
     * Check if design verification is complete (all outputs traced to inputs).
     */
    public function isVerificationComplete(): bool
    {
        return $this->designInputs !== []
            && $this->designOutputs !== []
            && $this->verificationResults !== [];
    }

    /**
     * Check if design validation is complete.
     */
    public function isValidationComplete(): bool
    {
        return $this->validationResults !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'device_identifier' => $this->deviceIdentifier,
            'project_name' => $this->projectName,
            'phase' => $this->phase->value,
            'created_at' => $this->createdAt->format('Y-m-d'),
            'verification_complete' => $this->isVerificationComplete(),
            'validation_complete' => $this->isValidationComplete(),
        ];

        if ($this->updatedAt !== null) {
            $data['updated_at'] = $this->updatedAt->format('Y-m-d');
        }

        if ($this->designInputs !== []) {
            $data['design_inputs'] = $this->designInputs;
        }

        if ($this->designOutputs !== []) {
            $data['design_outputs'] = $this->designOutputs;
        }

        if ($this->verificationResults !== []) {
            $data['verification_results'] = $this->verificationResults;
        }

        if ($this->validationResults !== []) {
            $data['validation_results'] = $this->validationResults;
        }

        if ($this->reviewNotes !== []) {
            $data['review_notes'] = $this->reviewNotes;
        }

        if ($this->changes !== []) {
            $data['changes'] = array_map(
                static fn(DesignChange $c): array => $c->toArray(),
                $this->changes,
            );
        }

        if ($this->designTransferNotes !== null) {
            $data['design_transfer_notes'] = $this->designTransferNotes;
        }

        return $data;
    }
}
