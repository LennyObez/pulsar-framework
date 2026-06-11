<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_bool;

/**
 * A reference to a code defined by a terminology system (code + system + display).
 *
 * @see https://www.hl7.org/fhir/datatypes.html#Coding
 * @api
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
     * @param array{
     *     system?: string|null,
     *     version?: string|null,
     *     code?: string|null,
     *     display?: string|null,
     *     userSelected?: bool|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $userSelected = $data['userSelected'] ?? null;

        return new self(
            system: Coerce::nullableString($data['system'] ?? null),
            version: Coerce::nullableString($data['version'] ?? null),
            code: Coerce::nullableString($data['code'] ?? null),
            display: Coerce::nullableString($data['display'] ?? null),
            userSelected: is_bool($userSelected) ? $userSelected : null,
        );
    }
}
