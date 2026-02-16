<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Pulsar\Api\Api;

/**
 * HTTP middleware contract.
 *
 * Extends PSR-15 MiddlewareInterface directly. All Pulsar middleware
 * is PSR-15 compatible without adapters.
 */
#[Api(since: '1.0.0')]
interface MiddlewareInterface extends PsrMiddlewareInterface {}
