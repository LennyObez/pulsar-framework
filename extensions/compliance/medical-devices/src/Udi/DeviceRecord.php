<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

/**
 * A registered medical device record.
 *
 * Tracks device identification, classification, and lifecycle status
 * per MDR requirements.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DeviceRecord
{
    public function __construct(
        public UdiIdentifier $udi,
        public string $deviceName,
        public string $manufacturer,
        public DeviceRiskClass $riskClass,
        public ?string $notifiedBody = null,
        public ?string $intendedPurpose = null,
        public DeviceStatus $status = DeviceStatus::Active,
        public ?string $certificateNumber = null,
        public ?string $certificateExpiry = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'udi' => $this->udi->toArray(),
            'device_name' => $this->deviceName,
            'manufacturer' => $this->manufacturer,
            'risk_class' => $this->riskClass->value,
            'status' => $this->status->value,
        ];

        if ($this->notifiedBody !== null) {
            $data['notified_body'] = $this->notifiedBody;
        }

        if ($this->intendedPurpose !== null) {
            $data['intended_purpose'] = $this->intendedPurpose;
        }

        if ($this->certificateNumber !== null) {
            $data['certificate_number'] = $this->certificateNumber;
        }

        if ($this->certificateExpiry !== null) {
            $data['certificate_expiry'] = $this->certificateExpiry;
        }

        return $data;
    }

    /**
     * @param array{
     *     udi: array<string, mixed>,
     *     device_name?: string,
     *     manufacturer?: string,
     *     risk_class?: string,
     *     notified_body?: string|null,
     *     intended_purpose?: string|null,
     *     status?: string,
     *     certificate_number?: string|null,
     *     certificate_expiry?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            udi: UdiIdentifier::fromArray($data['udi']),
            deviceName: $data['device_name'] ?? '',
            manufacturer: $data['manufacturer'] ?? '',
            riskClass: DeviceRiskClass::from($data['risk_class'] ?? ''),
            notifiedBody: $data['notified_body'] ?? null,
            intendedPurpose: $data['intended_purpose'] ?? null,
            status: DeviceStatus::tryFrom($data['status'] ?? '') ?? DeviceStatus::Active,
            certificateNumber: $data['certificate_number'] ?? null,
            certificateExpiry: $data['certificate_expiry'] ?? null,
        );
    }
}
