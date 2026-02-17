<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_bool;
use function is_string;

/**
 * A reference to a code defined by a terminology system (code + system + display).
 *
 * @see https://www.hl7.org/fhir/datatypes.html#Coding
 */
#[Api(since: '1.0.0')]
final readonly class Coding
{
    public function __construct(
        public ?string $system = null,
        public ?string $version = null,
        public ?string $code = null,
        public ?string $display = null,
        public ?bool $userSelected = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->system !== null) {
            $data['system'] = $this->system;
        }

        if ($this->version !== null) {
            $data['version'] = $this->version;
        }

        if ($this->code !== null) {
            $data['code'] = $this->code;
        }

        if ($this->display !== null) {
            $data['display'] = $this->display;
        }

        if ($this->userSelected !== null) {
            $data['userSelected'] = $this->userSelected;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            system: is_string($data['system'] ?? null) ? $data['system'] : null,
            version: is_string($data['version'] ?? null) ? $data['version'] : null,
            code: is_string($data['code'] ?? null) ? $data['code'] : null,
            display: is_string($data['display'] ?? null) ? $data['display'] : null,
            userSelected: is_bool($data['userSelected'] ?? null) ? $data['userSelected'] : null,
        );
    }
}
