<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskEngine;
use Pulsar\Security\AntiSpam\Risk\RiskBypassProviderInterface;
use Pulsar\Security\AntiSpam\Risk\RiskDecision;
use Pulsar\Security\AntiSpam\Risk\RiskSignal;
use Pulsar\Security\AntiSpam\Risk\RiskSignalProviderInterface;

use function array_values;

#[CoversClass(AdaptiveRiskEngine::class)]
final class AdaptiveRiskEngineTest extends TestCase
{
    #[Test]
    public function noSignalsScoresZeroAndAllows(): void
    {
        $assessment = $this->engine()->assess($this->request());

        self::assertSame(0.0, $assessment->score);
        self::assertSame(RiskDecision::Allow, $assessment->decision);
    }

    #[Test]
    public function lowSignalAllows(): void
    {
        $assessment = $this->engine($this->signal(0.2))->assess($this->request());

        self::assertSame(RiskDecision::Allow, $assessment->decision);
    }

    #[Test]
    public function midRiskEscalatesToChallenge(): void
    {
        $assessment = $this->engine($this->signal(0.6))->assess($this->request());

        self::assertSame(RiskDecision::Challenge, $assessment->decision);
    }

    #[Test]
    public function highRiskBlocks(): void
    {
        $assessment = $this->engine($this->signal(0.95))->assess($this->request());

        self::assertSame(RiskDecision::Block, $assessment->decision);
    }

    #[Test]
    public function combinesSignalsProbabilistically(): void
    {
        // 1 - (1-0.5)(1-0.5) = 0.75
        $assessment = $this->engine($this->signal(0.5), $this->signal(0.5))->assess($this->request());

        self::assertEqualsWithDelta(0.75, $assessment->score, 0.0001);
        self::assertSame(RiskDecision::Challenge, $assessment->decision);
        self::assertCount(2, $assessment->signals);
    }

    #[Test]
    public function clampsOutOfRangeSignalScores(): void
    {
        $assessment = $this->engine($this->signal(1.5))->assess($this->request());

        self::assertSame(1.0, $assessment->score);
        self::assertSame(RiskDecision::Block, $assessment->decision);
    }

    #[Test]
    public function bypassShortCircuitsToAllow(): void
    {
        $engine = new AdaptiveRiskEngine(
            new AdaptiveRiskConfig(enabled: true),
            [$this->signal(0.99)],
            [$this->bypass(true)],
        );

        $assessment = $engine->assess($this->request());

        self::assertSame(RiskDecision::Allow, $assessment->decision);
        self::assertTrue($assessment->bypassed);
        self::assertSame([], $assessment->signals, 'scoring is skipped on bypass');
    }

    private function engine(RiskSignalProviderInterface ...$providers): AdaptiveRiskEngine
    {
        return new AdaptiveRiskEngine(new AdaptiveRiskConfig(enabled: true), array_values($providers));
    }

    private function signal(float $score): RiskSignalProviderInterface
    {
        return new class ($score) implements RiskSignalProviderInterface {
            public function __construct(private readonly float $score) {}

            public function evaluate(ServerRequestInterface $request): RiskSignal
            {
                return new RiskSignal($this->score, 'test');
            }
        };
    }

    private function bypass(bool $bypass): RiskBypassProviderInterface
    {
        return new class ($bypass) implements RiskBypassProviderInterface {
            public function __construct(private readonly bool $bypass) {}

            public function shouldBypass(ServerRequestInterface $request): bool
            {
                return $this->bypass;
            }
        };
    }

    private function request(): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/');
    }
}
