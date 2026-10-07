<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamCheckInterface;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\AntiSpamPipeline;

#[CoversClass(AntiSpamPipeline::class)]
final class AntiSpamPipelineTest extends TestCase
{
    #[Test]
    public function evaluateRunsAllChecksInOrder(): void
    {
        $order = [];

        $check1 = $this->createCheck('first', true, function () use (&$order): void {
            $order[] = 'first';
        });
        $check2 = $this->createCheck('second', true, function () use (&$order): void {
            $order[] = 'second';
        });

        $pipeline = new AntiSpamPipeline([$check1, $check2]);
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');

        $result = $pipeline->evaluate($context);

        self::assertTrue($result->passed);
        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function evaluateCollectsAllResults(): void
    {
        $check1 = $this->createCheck('a', true);
        $check2 = $this->createCheck('b', false, score: 25);

        $pipeline = new AntiSpamPipeline([$check1, $check2]);
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');

        $result = $pipeline->evaluate($context);

        self::assertFalse($result->passed);
        self::assertCount(2, $result->checkResults);
    }

    #[Test]
    public function shortCircuitStopsOnFirstFailure(): void
    {
        $order = [];

        $check1 = $this->createCheck('first', false, function () use (&$order): void {
            $order[] = 'first';
        }, score: 50);
        $check2 = $this->createCheck('second', true, function () use (&$order): void {
            $order[] = 'second';
        });

        $pipeline = new AntiSpamPipeline([$check1, $check2], shortCircuit: true);
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');

        $result = $pipeline->evaluate($context);

        self::assertFalse($result->passed);
        self::assertSame(['first'], $order);
        self::assertCount(1, $result->checkResults);
    }

    #[Test]
    public function nonShortCircuitRunsAllChecksEvenOnFailure(): void
    {
        $check1 = $this->createCheck('first', false, score: 30);
        $check2 = $this->createCheck('second', false, score: 20);

        $pipeline = new AntiSpamPipeline([$check1, $check2], shortCircuit: false);
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');

        $result = $pipeline->evaluate($context);

        self::assertFalse($result->passed);
        self::assertCount(2, $result->checkResults);
        self::assertSame(50, $result->score);
    }

    #[Test]
    public function emptyPipelineReturnsPass(): void
    {
        $pipeline = new AntiSpamPipeline([]);
        $context = new AntiSpamContext(body: 'test', ipHash: 'ip');

        $result = $pipeline->evaluate($context);

        self::assertTrue($result->passed);
    }

    private function createCheck(
        string $name,
        bool $passes,
        ?Closure $callback = null,
        int $score = 0,
    ): AntiSpamCheckInterface {
        $stub = $this->createStub(AntiSpamCheckInterface::class);
        $stub->method('name')->willReturn($name);
        $stub->method('check')->willReturnCallback(
            function (AntiSpamContext $context) use ($name, $passes, $callback, $score): AntiSpamCheckResult {
                if ($callback !== null) {
                    $callback();
                }

                return $passes
                    ? AntiSpamCheckResult::pass($name)
                    : AntiSpamCheckResult::fail($name, $score, "Failed: $name");
            },
        );

        return $stub;
    }
}
