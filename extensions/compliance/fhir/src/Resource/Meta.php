<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_map;
use function is_array;
use function is_string;

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
        $lastUpdated = $data['lastUpdated'] ?? null;
        $rawSecurity = $data['security'] ?? null;
        $rawTag = $data['tag'] ?? null;

        $security = [];
        if (is_array($rawSecurity)) {
            foreach ($rawSecurity as $c) {
                if (is_array($c)) {
                    $security[] = Coding::fromArray($c);
                }
            }
        }

        $tag = [];
        if (is_array($rawTag)) {
            foreach ($rawTag as $c) {
                if (is_array($c)) {
                    $tag[] = Coding::fromArray($c);
                }
            }
        }

        return new self(
            versionId: Coerce::nullableString($data['versionId'] ?? null),
            lastUpdated: is_string($lastUpdated) ? new DateTimeImmutable($lastUpdated) : null,
            source: Coerce::nullableString($data['source'] ?? null),
            profile: Coerce::listOfString($data['profile'] ?? null),
            security: $security,
            tag: $tag,
        );
    }
}
