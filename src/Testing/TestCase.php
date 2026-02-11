<?php

declare(strict_types=1);

namespace Pulsar\Testing;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Pulsar\Api\Api;
use Pulsar\Testing\Concern\ResetsTestState;

/**
 * Pulsar base test case with automatic fake management.
 *
 * Extends PHPUnit TestCase with convenience methods for creating fakes
 * and guarantees all fakes are reset after each test — no state leakage.
 *
 * Usage:
 *   class MyTest extends TestCase
 *   {
 *       public function testSomething(): void
 *       {
 *           $events = $this->fakeEvents();
 *           // ... trigger some code ...
 *           $events->assertDispatched(OrderCreated::class);
 *       }
 *   }
 */
#[Api(since: '1.0.0')]
abstract class TestCase extends PHPUnitTestCase
{
    use ResetsTestState;

    protected function tearDown(): void
    {
        $this->resetTestState();
        parent::tearDown();
    }
}
