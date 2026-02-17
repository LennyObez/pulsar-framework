<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Clinical;

use Pulsar\Api\Api;

/**
 * Contract for clinical investigation data persistence.
 */
#[Api(since: '1.0.0')]
interface ClinicalDataRepositoryInterface
{
    /**
     * Store a clinical investigation record.
     */
    public function save(ClinicalInvestigation $investigation): void;

    /**
     * Retrieve a clinical investigation by ID.
     */
    public function find(string $id): ?ClinicalInvestigation;

    /**
     * Retrieve all investigations for a device.
     *
     * @return list<ClinicalInvestigation>
     */
    public function findByDevice(string $deviceIdentifier): array;

    /**
     * Retrieve investigations by status.
     *
     * @return list<ClinicalInvestigation>
     */
    public function findByStatus(ClinicalInvestigationStatus $status): array;
}
