<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * A CodeableConcept represents a value that is usually supplied by providing
 * a reference to one or more terminologies, but may also be defined by the
 * provision of text.
 *
 * @see https://www.hl7.org/fhir/datatypes.html#CodeableConcept
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CodeableConcept
{
    /**
     * @param list<Coding> $coding Code defined by a terminology system
     */
    public function __construct(
        public array $coding = [],
        public ?string $text = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->coding !== []) {
            $data['coding'] = array_map(
                static fn(Coding $c): array => $c->toArray(),
                $this->coding,
            );
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $codingList */
        $codingList = $data['coding'] ?? [];

        return new self(
            coding: array_values(array_map(
                static fn(array $c): Coding => Coding::fromArray($c),
                $codingList,
            )),
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
        );
    }
}
