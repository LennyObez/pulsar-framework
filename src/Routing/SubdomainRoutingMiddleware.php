<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * PSR-15 middleware that resolves the domain context and attaches it
 * to the request as an attribute.
 *
 * When subdomain mappings are configured, this middleware resolves
 * which subdomain the request targets and stores the DomainContext
 * on the request for downstream consumers (route matching, URL
 * generation, extension scope filtering).
 *
 * When no subdomain mappings exist (the default), this middleware
 * is a near-zero-cost passthrough that attaches a default context.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SubdomainRoutingMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'pulsar.domain_context';

    public function __construct(
        private DomainResolverInterface $resolver,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = $this->resolver->resolve($request);

        $request = $request->withAttribute(self::ATTRIBUTE, $context);

        return $handler->handle($request);
    }
}
