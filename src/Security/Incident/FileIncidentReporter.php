<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;

use function array_filter;
use function array_slice;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function sprintf;
use function trim;
use function usort;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * Append-only JSONL file-based incident reporter.
 *
 * Each incident is written as a single JSON line with LOCK_EX for concurrent
 * write safety. The log directory is created with 0750 permissions if it
 * does not exist. Suitable for production use.
 */
#[Api(since: '1.0.0')]
final class FileIncidentReporter implements IncidentReporterInterface
{
    private const int DIR_PERMISSIONS = 0o750;

    public function __construct(
        private readonly string $logPath,
    ) {}

    #[Override]
    public function report(
        IncidentSeverity $severity,
        string $title,
        string $description,
        string $source = '',
        array $metadata = [],
    ): IncidentInterface {
        $incident = Incident::create($severity, $title, $description, $source, $metadata);

        $this->ensureDirectory();
        $this->appendIncident($incident);

        return $incident;
    }

    #[Override]
    public function find(string $id): ?IncidentInterface
    {
        foreach ($this->readAll() as $incident) {
            if ($incident->id() === $id) {
                return $incident;
            }
        }

        return null;
    }

    #[Override]
    public function recent(int $limit = 50, ?IncidentSeverity $minSeverity = null): array
    {
        $incidents = $this->readAll();

        if ($minSeverity !== null) {
            $minOrdinal = self::severityOrdinal($minSeverity);
            $incidents = array_filter(
                $incidents,
                static fn(Incident $i): bool => self::severityOrdinal($i->severity()) >= $minOrdinal,
            );
        }

        $sorted = array_values($incidents);
        usort(
            $sorted,
            static fn(Incident $a, Incident $b): int => $b->reportedAt()->getTimestamp() <=> $a->reportedAt()->getTimestamp(),
        );

        return array_slice($sorted, 0, $limit);
    }

    /**
     * @throws JsonException
     */
    private function appendIncident(Incident $incident): void
    {
        $line = json_encode(
            $incident->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";

        $result = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw SecurityException::auditWriteFailed(
                sprintf('Could not write to incident log at "%s"', $this->logPath),
            );
        }
    }

    /**
     * @return list<Incident>
     */
    private function readAll(): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $contents = file_get_contents($this->logPath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $lines = explode("\n", trim($contents));
        $incidents = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($line, true);

            if (!is_array($data) || !isset($data['id'], $data['severity'], $data['title'], $data['description'], $data['reported_at'], $data['source'])) {
                continue;
            }

            /** @var array{id: string, severity: string, title: string, description: string, reported_at: string, source: string, metadata?: array<string, mixed>} $data */
            $incidents[] = Incident::fromArray($data);
        }

        return $incidents;
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->logPath);

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, self::DIR_PERMISSIONS, true) && !is_dir($dir)) {
            throw SecurityException::auditWriteFailed(
                sprintf('Could not create incident log directory "%s"', $dir),
            );
        }
    }

    private static function severityOrdinal(IncidentSeverity $severity): int
    {
        return match ($severity) {
            IncidentSeverity::Low => 0,
            IncidentSeverity::Medium => 1,
            IncidentSeverity::High => 2,
            IncidentSeverity::Critical => 3,
        };
    }
}
