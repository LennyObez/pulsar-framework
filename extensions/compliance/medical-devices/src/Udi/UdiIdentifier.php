<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

use function is_string;

/**
 * Unique Device Identifier per EU MDR Article 27.
 *
 * A UDI consists of a Device Identifier (DI) and a Production Identifier (PI).
 * The DI identifies the device labeller and the specific version/model.
 * The PI identifies the production-specific data (lot, serial, expiry, date).
 *
 * @see https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0745 (Article 27)
 */
#[Api(since: '1.0.0')]
final readonly class UdiIdentifier
{
    public function __construct(
        public string $deviceIdentifier,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $expirationDate = null,
        public ?string $manufacturingDate = null,
        public UdiIssuingAgency $issuingAgency = UdiIssuingAgency::GS1,
        public ?string $humanReadable = null,
    ) {}

    /**
     * Get the full UDI string combining DI and PI components.
     */
    public function fullUdi(): string
    {
        $parts = [$this->deviceIdentifier];

        if ($this->lotNumber !== null) {
            $parts[] = "LOT:{$this->lotNumber}";
        }

        if ($this->serialNumber !== null) {
            $parts[] = "SN:{$this->serialNumber}";
        }

        if ($this->expirationDate !== null) {
            $parts[] = "EXP:{$this->expirationDate}";
        }

        if ($this->manufacturingDate !== null) {
            $parts[] = "MFG:{$this->manufacturingDate}";
        }

        return implode('|', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'device_identifier' => $this->deviceIdentifier,
            'issuing_agency' => $this->issuingAgency->value,
        ];

        if ($this->lotNumber !== null) {
            $data['lot_number'] = $this->lotNumber;
        }

        if ($this->serialNumber !== null) {
            $data['serial_number'] = $this->serialNumber;
        }

        if ($this->expirationDate !== null) {
            $data['expiration_date'] = $this->expirationDate;
        }

        if ($this->manufacturingDate !== null) {
            $data['manufacturing_date'] = $this->manufacturingDate;
        }

        if ($this->humanReadable !== null) {
            $data['human_readable'] = $this->humanReadable;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $deviceIdentifier */
        $deviceIdentifier = $data['device_identifier'] ?? '';

        return new self(
            deviceIdentifier: $deviceIdentifier,
            lotNumber: is_string($data['lot_number'] ?? null) ? $data['lot_number'] : null,
            serialNumber: is_string($data['serial_number'] ?? null) ? $data['serial_number'] : null,
            expirationDate: is_string($data['expiration_date'] ?? null) ? $data['expiration_date'] : null,
            manufacturingDate: is_string($data['manufacturing_date'] ?? null) ? $data['manufacturing_date'] : null,
            issuingAgency: is_string($data['issuing_agency'] ?? null)
                ? UdiIssuingAgency::from($data['issuing_agency'])
                : UdiIssuingAgency::GS1,
            humanReadable: is_string($data['human_readable'] ?? null) ? $data['human_readable'] : null,
        );
    }
}
