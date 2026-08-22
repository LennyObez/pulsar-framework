<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Psr\Http\Server\RequestHandlerInterface as PsrRequestHandlerInterface;
use Pulsar\Api\Api;

/**
 * Request handler contract.
 *
 * Extends PSR-15 RequestHandlerInterface for Pulsar marker typing.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface RequestHandlerInterface extends PsrRequestHandlerInterface {}
