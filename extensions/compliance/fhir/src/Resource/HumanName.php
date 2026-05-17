<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * A name of a human with text, parts, and usage information.
 *
 * @see https://www.hl7.org/fhir/datatypes.html#HumanName
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HumanName
{
    /**
     * @param list<string> $given   Given names (not always 'first')
     * @param list<string> $prefix  Parts that come before the name (e.g. "Dr")
     * @param list<string> $suffix  Parts that come after the name (e.g. "Jr")
     */
    public function __construct(
        public ?string $use = null,
        public ?string $text = null,
        public ?string $family = null,
        public array $given = [],
        public array $prefix = [],
        public array $suffix = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->use !== null) {
            $data['use'] = $this->use;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->family !== null) {
            $data['family'] = $this->family;
        }

        if ($this->given !== []) {
            $data['given'] = $this->given;
        }

        if ($this->prefix !== []) {
            $data['prefix'] = $this->prefix;
        }

        if ($this->suffix !== []) {
            $data['suffix'] = $this->suffix;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $given */
        $given = $data['given'] ?? [];
        /** @var list<string> $prefix */
        $prefix = $data['prefix'] ?? [];
        /** @var list<string> $suffix */
        $suffix = $data['suffix'] ?? [];

        return new self(
            use: is_string($data['use'] ?? null) ? $data['use'] : null,
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
            family: is_string($data['family'] ?? null) ? $data['family'] : null,
            given: $given,
            prefix: $prefix,
            suffix: $suffix,
        );
    }
}
