<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Tests\Benchmark\Support\BenchmarkPipelineFactory;
use Pulsar\Tests\Benchmark\Support\ManifestValidator;

/**
 * End-to-end request lifecycle benchmarks (Tier A: in-memory stubs).
 *
 * Each benchmark exercises a full request-class pipeline as defined by
 * the semantic contracts in bench-pipeline.manifest.php.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class EndToEndBench
{
    private BenchmarkPipelineFactory $factory;
    private RequestHandlerInterface $handler;
    private ServerRequestInterface $request;
    private ServerRequestInterface $authenticatedRequest;
    private ServerRequestInterface $tokenRequest;

    private MiddlewarePipeline $anonymousPipeline;
    private MiddlewarePipeline $authenticatedSessionPipeline;
    private MiddlewarePipeline $authenticatedTokenPipeline;
    private MiddlewarePipeline $withAuditPipeline;
    private MiddlewarePipeline $complianceEventPipeline;

    public function setUp(): void
    {
        // Validate manifest contracts before running benchmarks
        /** @var array<string, mixed> $manifest */
        $manifest = require __DIR__ . '/../../tools/php/bench-pipeline.manifest.php';
        new ManifestValidator()->assertValid($manifest);

        $this->factory = new BenchmarkPipelineFactory();
        $this->handler = $this->factory->createHandler();

        $this->request = new ServerRequest(method: 'GET', uri: '/bench/api/resource');
        $this->request = $this->request->withHeader('Accept', 'application/json');

        $this->authenticatedRequest = $this->request;

        $this->tokenRequest = $this->request->withHeader('Authorization', 'Bearer bench-token-001');

        $this->anonymousPipeline = $this->factory->createPipeline('request.anonymous_json_api');
        $this->authenticatedSessionPipeline = $this->factory->createPipeline('request.authenticated_session');
        $this->authenticatedTokenPipeline = $this->factory->createPipeline('request.authenticated_token');
        $this->withAuditPipeline = $this->factory->createPipeline('request.with_audit');
        $this->complianceEventPipeline = $this->factory->createPipeline('request.compliance_event');
    }

    /**
     * Anonymous JSON API request — full pipeline with routing + content negotiation.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 2 milliseconds')]
    public function benchAnonymousJsonApi(): void
    {
        $this->anonymousPipeline->process($this->request, $this->handler);
    }

    /**
     * Session-authenticated request — session load + verify + guard + authorize.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 3 milliseconds')]
    public function benchAuthenticatedSession(): void
    {
        $this->authenticatedSessionPipeline->process($this->authenticatedRequest, $this->handler);
    }

    /**
     * Token-authenticated request — token resolve + verify + guard + authorize.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 2 milliseconds')]
    public function benchAuthenticatedToken(): void
    {
        $this->authenticatedTokenPipeline->process($this->tokenRequest, $this->handler);
    }

    /**
     * Request with full audit trail — auth + audit log write + HMAC signing.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 4 milliseconds')]
    public function benchWithAudit(): void
    {
        $this->withAuditPipeline->process($this->authenticatedRequest, $this->handler);
    }

    /**
     * Request with compliance event — auth + audit + compliance event dispatch.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 milliseconds')]
    public function benchComplianceEvent(): void
    {
        $this->complianceEventPipeline->process($this->authenticatedRequest, $this->handler);
    }
}
