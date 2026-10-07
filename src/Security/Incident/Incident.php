<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Immutable value object representing a security incident.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Incident implements IncidentInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $id,
        private IncidentSeverity $severity,
        private string $title,
        private string $description,
        private DateTimeImmutable $reportedAt,
        private string $source,
        private array $metadata = [],
    ) {}

    /**
     * Create a new incident with an auto-generated ID and current timestamp.
     *
     * @param array<string, mixed> $metadata
     */
    public static function create(
        IncidentSeverity $severity,
        string $title,
        string $description,
        string $source = '',
        array $metadata = [],
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            severity: $severity,
            title: $title,
            description: $description,
            reportedAt: new DateTimeImmutable(),
            source: $source,
            metadata: $metadata,
        );
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function severity(): IncidentSeverity
    {
        return $this->severity;
    }

    #[Override]
    public function title(): string
    {
        return $this->title;
    }

    #[Override]
    public function description(): string
    {
        return $this->description;
    }

    #[Override]
    public function reportedAt(): DateTimeImmutable
    {
        return $this->reportedAt;
    }

    #[Override]
    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * Serialize the incident to an array for storage.
     *
     * @return array{id: string, severity: string, title: string, description: string, reported_at: string, source: string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'description' => $this->description,
            'reported_at' => $this->reportedAt->format(DateTimeImmutable::ATOM),
            'source' => $this->source,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Reconstruct an incident from a stored array.
     *
     * @param array{id: string, severity: string, title: string, description: string, reported_at: string, source: string, metadata?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            severity: IncidentSeverity::from($data['severity']),
            title: $data['title'],
            description: $data['description'],
            reportedAt: new DateTimeImmutable($data['reported_at']),
            source: $data['source'],
            metadata: $data['metadata'] ?? [],
        );
    }
}
