<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Compliance\Event\Gdpr\DataAccessRequested;

/**
 * Benchmark compliance dispatcher middleware.
 *
 * Dispatches a compliance event for each request via NullComplianceDispatcher.
 */
final class BenchComplianceDispatcherMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly NullComplianceDispatcher $dispatcher,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $this->dispatcher->dispatch(
            new DataAccessRequested(
                eventId: 'bench-compliance-001',
                occurredAt: new DateTimeImmutable(),
                correlationId: 'bench-corr-001',
                nonce: 'bench-nonce-001',
                subjectId: 'bench-subject-1',
                requesterIdentity: 'bench-user-1',
                dataCategories: ['profile'],
                legalBasis: 'legitimate_interest',
            ),
        );

        return $response;
    }
}
