<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function array_map;
use function array_values;

/**
 * FHIR Resource metadata.
 *
 * @see https://www.hl7.org/fhir/resource.html#Meta
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Meta
{
    /**
     * @param list<string> $profile  Canonical URLs of profiles this resource claims to conform to
     * @param list<Coding>  $security Security labels applied to this resource
     * @param list<Coding>  $tag     Tags applied to this resource
     */
    public function __construct(
        public ?string $versionId = null,
        public ?DateTimeImmutable $lastUpdated = null,
        public ?string $source = null,
        public array $profile = [],
        public array $security = [],
        public array $tag = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->versionId !== null) {
            $data['versionId'] = $this->versionId;
        }

        if ($this->lastUpdated !== null) {
            $data['lastUpdated'] = $this->lastUpdated->format('Y-m-d\TH:i:s.vP');
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        if ($this->profile !== []) {
            $data['profile'] = $this->profile;
        }

        if ($this->security !== []) {
            $data['security'] = array_map(
                static fn(Coding $c): array => $c->toArray(),
                $this->security,
            );
        }

        if ($this->tag !== []) {
            $data['tag'] = array_map(
                static fn(Coding $c): array => $c->toArray(),
                $this->tag,
            );
        }

        return $data;
    }

    /**
     * @param array{
     *     versionId?: string|null,
     *     lastUpdated?: string|null,
     *     source?: string|null,
     *     profile?: list<string>,
     *     security?: list<array<string, mixed>>,
     *     tag?: list<array<string, mixed>>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            versionId: $data['versionId'] ?? null,
            lastUpdated: isset($data['lastUpdated']) ? new DateTimeImmutable($data['lastUpdated']) : null,
            source: $data['source'] ?? null,
            profile: $data['profile'] ?? [],
            security: array_values(array_map(
                static fn(array $c): Coding => Coding::fromArray($c),
                $data['security'] ?? [],
            )),
            tag: array_values(array_map(
                static fn(array $c): Coding => Coding::fromArray($c),
                $data['tag'] ?? [],
            )),
        );
    }
}
