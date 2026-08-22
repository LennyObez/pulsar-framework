<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\Risk\AdaptiveChallengeMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskEngine;
use Pulsar\Security\AntiSpam\Risk\RiskAssessment;
use Pulsar\Security\AntiSpam\Risk\RiskDecision;
use Pulsar\Security\AntiSpam\Risk\RiskSignal;
use Pulsar\Security\AntiSpam\Risk\RiskSignalProviderInterface;

#[CoversClass(AdaptiveChallengeMiddleware::class)]
final class AdaptiveChallengeMiddlewareTest extends TestCase
{
    #[Test]
    public function blocksHighRiskRequests(): void
    {
        $response = $this->middleware(0.95)->process($this->request(), $this->okHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function allowsAndAttachesAssessment(): void
    {
        $handler = $this->capturingHandler();

        $response = $this->middleware(0.1)->process($this->request(), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(RiskAssessment::class, $handler->captured);
        self::assertSame(RiskDecision::Allow, $handler->captured->decision);
    }

    #[Test]
    public function challengeDecisionPassesThroughWithAssessmentExposed(): void
    {
        $handler = $this->capturingHandler();

        $response = $this->middleware(0.6)->process($this->request(), $handler);

        self::assertSame(200, $response->getStatusCode(), 'challenge is advisory at the middleware; enforced by downstream form layer');
        self::assertSame(RiskDecision::Challenge, $handler->captured?->decision);
    }

    private function middleware(float $signalScore): AdaptiveChallengeMiddleware
    {
        $provider = new class ($signalScore) implements RiskSignalProviderInterface {
            public function __construct(private readonly float $score) {}

            public function evaluate(ServerRequestInterface $request): RiskSignal
            {
                return new RiskSignal($this->score, 'test');
            }
        };

        return new AdaptiveChallengeMiddleware(new AdaptiveRiskEngine(new AdaptiveRiskConfig(enabled: true), [$provider]));
    }

    private function request(): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/');
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('OK');
            }
        };
    }

    /**
     * @return RequestHandlerInterface&object{captured: ?RiskAssessment}
     */
    private function capturingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?RiskAssessment $captured = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $assessment = $request->getAttribute(RiskAssessment::REQUEST_ATTRIBUTE);

                if ($assessment instanceof RiskAssessment) {
                    $this->captured = $assessment;
                }

                return Response::text('OK');
            }
        };
    }
}
