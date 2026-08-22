<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

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
     * @param array{
     *     use?: string|null,
     *     text?: string|null,
     *     family?: string|null,
     *     given?: list<string>,
     *     prefix?: list<string>,
     *     suffix?: list<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            use: $data['use'] ?? null,
            text: $data['text'] ?? null,
            family: $data['family'] ?? null,
            given: $data['given'] ?? [],
            prefix: $data['prefix'] ?? [],
            suffix: $data['suffix'] ?? [],
        );
    }
}
