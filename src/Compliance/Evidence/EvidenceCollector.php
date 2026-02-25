<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;

/**
 * Collects and stores compliance evidence for controls.
 *
 * Evidence proves that a control is implemented and operational.
 * Collectors can be registered per evidence type to automate collection.
 */
#[Api(since: '1.0.0')]
final class EvidenceCollector
{
    /** @var array<string, callable(string): list<EvidenceRecord>> type → collector callback */
    private array $collectors = [];

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly EvidenceStoreInterface $store,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Register an automated evidence collector for a specific type.
     *
     * The callback receives a control ID and returns evidence records.
     *
     * @param callable(string): list<EvidenceRecord> $collector
     */
    public function registerCollector(string $type, callable $collector): void
    {
        $this->collectors[$type] = $collector;
    }

    /**
     * Manually collect a single evidence record.
     *
     * @param array<string, mixed> $data
     */
    public function collect(
        string $controlId,
        string $type,
        string $description,
        array $data = [],
        ?string $signature = null,
    ): EvidenceRecord {
        $record = new EvidenceRecord(
            id: bin2hex($this->randomizer->getBytes(16)),
            controlId: $controlId,
            type: $type,
            description: $description,
            data: $data,
            collectedAt: new DateTimeImmutable(),
            signature: $signature,
        );

        $this->store->store($record);

        return $record;
    }

    /**
     * Run all registered automated collectors for a control.
     *
     * @return list<EvidenceRecord>
     */
    public function collectAll(string $controlId): array
    {
        $results = [];

        foreach ($this->collectors as $collector) {
            foreach ($collector($controlId) as $record) {
                $this->store->store($record);
                $results[] = $record;
            }
        }

        return $results;
    }

    /**
     * Run a specific collector type for a control.
     *
     * @return list<EvidenceRecord>
     */
    public function collectByType(string $controlId, string $type): array
    {
        if (!isset($this->collectors[$type])) {
            return [];
        }

        $results = ($this->collectors[$type])($controlId);

        foreach ($results as $record) {
            $this->store->store($record);
        }

        return $results;
    }

    /**
     * Get the evidence store.
     */
    public function store(): EvidenceStoreInterface
    {
        return $this->store;
    }
}
