<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\Service;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

#[CoversClass(Service::class)]
final class ServiceTest extends TestCase
{
    private function makeService(bool $active = true): Service
    {
        return new Service(
            id: 'svc-001',
            name: 'Haircut',
            description: 'Standard haircut',
            duration: 30,
            basePrice: Money::of(5000, Currency::USD),
            depositPercent: 20,
            categoryId: 'cat-001',
            active: $active,
        );
    }

    public function testDepositAmountCalculation(): void
    {
        $service = $this->makeService();

        $deposit = $service->depositAmount();

        // 20% of 5000 = 1000
        self::assertSame(1000, $deposit->amount);
        self::assertSame(Currency::USD, $deposit->currency);
    }

    public function testDeactivate(): void
    {
        $service = $this->makeService(active: true);

        $deactivated = $service->deactivate();

        self::assertFalse($deactivated->active);
        self::assertTrue($service->active);
    }

    public function testActivate(): void
    {
        $service = $this->makeService(active: false);

        $activated = $service->activate();

        self::assertTrue($activated->active);
        self::assertFalse($service->active);
    }

    public function testPropertiesRetained(): void
    {
        $service = $this->makeService();

        self::assertSame('svc-001', $service->id);
        self::assertSame('Haircut', $service->name);
        self::assertSame('Standard haircut', $service->description);
        self::assertSame(30, $service->duration);
        self::assertSame('cat-001', $service->categoryId);
    }

    public function testZeroDepositPercent(): void
    {
        $service = new Service(
            id: 'svc-002',
            name: 'Consultation',
            description: 'Free consultation',
            duration: 15,
            basePrice: Money::of(10000, Currency::EUR),
            depositPercent: 0,
            categoryId: null,
            active: true,
        );

        $deposit = $service->depositAmount();

        self::assertTrue($deposit->isZero());
    }
}
