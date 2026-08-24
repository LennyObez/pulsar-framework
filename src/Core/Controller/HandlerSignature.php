<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Pulsar\Api\Api;

/**
 * The reflected shape of a routed handler, as the kernel sees it.
 *
 * `$parameters` lists exactly the parameters the kernel intends to fill from
 * the route — that is, every declared parameter EXCEPT a leading
 * ServerRequestInterface, which the kernel supplies itself and which no
 * resolver may claim. A claim on any name absent from this list is ignored.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class HandlerSignature
{
    /**
     * @param class-string           $class
     * @param string                 $method     Method name, or '__invoke' for an invokable controller.
     * @param list<HandlerParameter> $parameters In declaration order.
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $parameters,
    ) {}
}
