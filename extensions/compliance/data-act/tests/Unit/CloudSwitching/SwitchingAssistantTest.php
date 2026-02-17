<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\CloudSwitching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingAssistant;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingStatus;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Internal\InMemoryDataPortabilityService;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

#[CoversClass(SwitchingAssistant::class)]
final class SwitchingAssistantTest extends TestCase
{
    #[Test]
    public function initiateSwitchingCreatesPlan(): void
    {
        $assistant = $this->createAssistant();

        $plan = $assistant->initiateSwitching('cust-1', 'aws');

        self::assertSame('cust-1', $plan->customerId);
        self::assertSame('aws', $plan->targetProvider);
        self::assertSame(SwitchingStatus::Initiated, $plan->status);
        self::assertSame(ExportStatus::Pending, $plan->exportRequest->status);
    }

    #[Test]
    public function initiateSwitchingUsesConfiguredTransitionPeriod(): void
    {
        $config = new DataActConfig(switchingTransitionDays: 45);
        $assistant = $this->createAssistant($config);

        $plan = $assistant->initiateSwitching('cust-1', 'azure');

        $daysDiff = $plan->transitionDeadline->diff($plan->initiatedAt)->days;
        self::assertSame(45, $daysDiff);
    }

    #[Test]
    public function initiateSwitchingUsesCustomExportFormat(): void
    {
        $assistant = $this->createAssistant();

        $plan = $assistant->initiateSwitching('cust-1', 'gcp', 'csv');

        self::assertSame('csv', $plan->exportRequest->format);
    }

    #[Test]
    public function initiateSwitchingUsesDefaultFormatWhenEmpty(): void
    {
        $config = new DataActConfig(defaultExportFormat: 'xml');
        $assistant = $this->createAssistant($config);

        $plan = $assistant->initiateSwitching('cust-1', 'gcp');

        self::assertSame('xml', $plan->exportRequest->format);
    }

    #[Test]
    public function plansForCustomerReturnsOnlyMatchingPlans(): void
    {
        $assistant = $this->createAssistant();
        (void) $assistant->initiateSwitching('cust-1', 'aws');
        (void) $assistant->initiateSwitching('cust-2', 'azure');
        (void) $assistant->initiateSwitching('cust-1', 'gcp');

        $cust1Plans = $assistant->plansForCustomer('cust-1');
        $cust2Plans = $assistant->plansForCustomer('cust-2');
        $cust3Plans = $assistant->plansForCustomer('cust-3');

        self::assertCount(2, $cust1Plans);
        self::assertCount(1, $cust2Plans);
        self::assertCount(0, $cust3Plans);
    }

    #[Test]
    public function transitionPeriodDaysReturnsConfiguredValue(): void
    {
        $config = new DataActConfig(switchingTransitionDays: 60);
        $assistant = $this->createAssistant($config);

        self::assertSame(60, $assistant->transitionPeriodDays());
    }

    private function createAssistant(?DataActConfig $config = null): SwitchingAssistant
    {
        $config ??= new DataActConfig();

        return new SwitchingAssistant(
            $config,
            new InMemoryDataPortabilityService($config),
        );
    }
}
