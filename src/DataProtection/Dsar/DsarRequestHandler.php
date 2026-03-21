<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

use function bin2hex;

/**
 * Orchestrates the DSAR (Data Subject Access Request) workflow.
 *
 * Manages the lifecycle of a DSAR from submission through data
 * collection, packaging, and delivery. Enforces the 30-day GDPR
 * deadline and coordinates all registered data collectors.
 * @api
 */
#[Api(since: '1.0.0')]
final class DsarRequestHandler
{
    /** GDPR Article 12(3): response deadline in days. */
    private const int DEADLINE_DAYS = 30;

    private readonly Randomizer $randomizer;

    /**
     * @param list<DsarCollectorInterface> $collectors Registered data collectors
     * @param DsarPackager $packager Package assembler
     * @param DsarStoreInterface $store Request persistence
     */
    public function __construct(
        private readonly array $collectors,
        private readonly DsarPackager $packager,
        private readonly DsarStoreInterface $store,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    /**
     * Submit a new DSAR.
     *
     * Creates a request with a 30-day deadline and returns it
     * in Pending status awaiting identity verification.
     */
    #[NoDiscard]
    public function submit(string $subjectId, string $email): DsarRequest
    {
        $now = new DateTimeImmutable();

        $request = new DsarRequest(
            id: bin2hex($this->randomizer->getBytes(16)),
            subjectId: $subjectId,
            email: $email,
            status: DsarStatus::Pending,
            createdAt: $now,
            deadline: $now->modify('+' . self::DEADLINE_DAYS . ' days'),
            verificationToken: bin2hex($this->randomizer->getBytes(32)),
        );

        $this->store->save($request);

        return $request;
    }

    /**
     * Process a verified DSAR: collect data from all sources and build the package.
     *
     * @return DsarRequest The request updated with the package path
     */
    public function process(string $requestId): DsarRequest
    {
        $request = $this->store->findById($requestId);

        if ($request === null) {
            throw new RuntimeException('DSAR request not found: ' . $requestId);
        }

        // Update status to processing
        $request = new DsarRequest(
            id: $request->id,
            subjectId: $request->subjectId,
            email: $request->email,
            status: DsarStatus::Processing,
            createdAt: $request->createdAt,
            deadline: $request->deadline,
            verificationToken: $request->verificationToken,
        );
        $this->store->save($request);

        // Collect data from all registered collectors
        $dataSets = [];
        foreach ($this->collectors as $collector) {
            $dataSets[] = $collector->collect($request->subjectId);
        }

        // Build the data package
        $packagePath = $this->packager->package($request, $dataSets);

        // Update to completed
        $request = new DsarRequest(
            id: $request->id,
            subjectId: $request->subjectId,
            email: $request->email,
            status: DsarStatus::Completed,
            createdAt: $request->createdAt,
            deadline: $request->deadline,
            completedAt: new DateTimeImmutable(),
            packagePath: $packagePath,
            verificationToken: $request->verificationToken,
        );
        $this->store->save($request);

        return $request;
    }

    /**
     * Get all overdue requests (past 30-day deadline, not completed).
     *
     * @return list<DsarRequest>
     */
    #[NoDiscard]
    public function getOverdueRequests(): array
    {
        $all = $this->store->findAll();
        $overdue = [];

        foreach ($all as $request) {
            if ($request->isOverdue()) {
                $overdue[] = $request;
            }
        }

        return $overdue;
    }

    /**
     * Find a request by ID.
     */
    #[NoDiscard]
    public function findRequest(string $requestId): ?DsarRequest
    {
        return $this->store->findById($requestId);
    }
}
