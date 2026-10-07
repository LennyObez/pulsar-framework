<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;

#[CoversClass(TimelineController::class)]
final class TimelineControllerTest extends TestCase
{
    #[Test]
    public function classIsInstantiable(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $builder = new TimelineBuilder($store);
        $controller = new TimelineController($builder);

        self::assertInstanceOf(TimelineController::class, $controller);
    }
}
