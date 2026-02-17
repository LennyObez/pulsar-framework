<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\ThirdParty;

use Pulsar\Api\Api;

/**
 * Contract for the ICT third-party provider register per DORA Article 28.
 */
#[Api(since: '1.0.0')]
interface ThirdPartyRegisterInterface
{
    /**
     * Register a third-party ICT provider.
     */
    public function register(ThirdPartyProvider $provider): void;

    /**
     * Retrieve a provider by ID.
     */
    public function find(string $id): ?ThirdPartyProvider;

    /**
     * List all registered providers, optionally filtered by risk level.
     *
     * @return list<ThirdPartyProvider>
     */
    public function listProviders(?ThirdPartyRiskLevel $riskLevel = null): array;

    /**
     * Identify providers supporting critical or important functions.
     *
     * @return list<ThirdPartyProvider>
     */
    public function criticalProviders(): array;

    /**
     * Analyze concentration risk: identify services where multiple critical
     * functions depend on the same provider.
     *
     * @return list<ConcentrationRiskResult>
     */
    public function analyzeConcentrationRisk(): array;
}
