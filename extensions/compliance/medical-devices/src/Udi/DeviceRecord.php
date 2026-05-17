<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $udiData */
        $udiData = $data['udi'];
        /** @var string $deviceName */
        $deviceName = $data['device_name'] ?? '';
        /** @var string $manufacturer */
        $manufacturer = $data['manufacturer'] ?? '';
        /** @var string $riskClassStr */
        $riskClassStr = $data['risk_class'] ?? '';

        return new self(
            udi: UdiIdentifier::fromArray($udiData),
            deviceName: $deviceName,
            manufacturer: $manufacturer,
            riskClass: DeviceRiskClass::from($riskClassStr),
            notifiedBody: is_string($data['notified_body'] ?? null) ? $data['notified_body'] : null,
            intendedPurpose: is_string($data['intended_purpose'] ?? null) ? $data['intended_purpose'] : null,
            status: is_string($data['status'] ?? null) ? DeviceStatus::from($data['status']) : DeviceStatus::Active,
            certificateNumber: is_string($data['certificate_number'] ?? null) ? $data['certificate_number'] : null,
            certificateExpiry: is_string($data['certificate_expiry'] ?? null) ? $data['certificate_expiry'] : null,
        );
    }
}
