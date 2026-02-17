<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\CloudSwitching;

use DateInterval;
use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Portability\DataPortabilityService;

/**
 * Cloud switching assistant per Data Act Articles 23-26.
 *
 * Assists customers in migrating data from one cloud service provider
 * to another, ensuring transition periods, data availability, and
 * functional equivalence as required by the regulation.
 */
#[Api(since: '1.0.0')]
final class SwitchingAssistant
{
    /** @var list<SwitchingPlan> */
    private array $plans = [];

    public function __construct(
        private readonly DataActConfig $config,
        private readonly DataPortabilityService $portability,
    ) {}

    /**
     * Initiate a cloud switching plan for a customer.
     *
     * Creates a structured migration plan with timeline, data export
     * steps, and target provider details per Art. 25.
     */
    #[NoDiscard]
    public function initiateSwitching(
        string $customerId,
        string $targetProvider,
        string $exportFormat = '',
    ): SwitchingPlan {
        $now = new DateTimeImmutable();
        $transitionEnd = $now->add(
            new DateInterval('P' . $this->config->switchingTransitionDays . 'D'),
        );

        $format = $exportFormat !== '' ? $exportFormat : $this->config->defaultExportFormat;
        $exportRequest = $this->portability->requestExport($customerId, $format);

        $plan = new SwitchingPlan(
            customerId: $customerId,
            targetProvider: $targetProvider,
            exportRequest: $exportRequest,
            initiatedAt: $now,
            transitionDeadline: $transitionEnd,
            status: SwitchingStatus::Initiated,
        );

        $this->plans[] = $plan;

        return $plan;
    }

    /**
     * Get all switching plans for a customer.
     *
     * @return list<SwitchingPlan>
     */
    #[NoDiscard]
    public function plansForCustomer(string $customerId): array
    {
        $result = [];

        foreach ($this->plans as $plan) {
            if ($plan->customerId === $customerId) {
                $result[] = $plan;
            }
        }

        return $result;
    }

    /**
     * Get the configured transition period in days.
     */
    #[NoDiscard]
    public function transitionPeriodDays(): int
    {
        return $this->config->switchingTransitionDays;
    }
}
