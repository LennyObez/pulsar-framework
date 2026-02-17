<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;

/**
 * Factory for creating HTTP responses in the WAF context.
 *
 * Abstracted to avoid coupling the WAF engine to a specific PSR-7 implementation.
 */
#[Api(since: '1.0.0')]
interface ResponseFactoryInterface
{
    public function createResponse(int $statusCode, string $reasonPhrase = ''): ResponseInterface;
}
