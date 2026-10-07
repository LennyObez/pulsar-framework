<?php

declare(strict_types=1);

namespace Pulsar\Http\Factory;

use NoDiscard;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;

/**
 * PSR-17 response factory.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class ResponseFactory implements ResponseFactoryInterface
{
    #[NoDiscard]
    #[Override]
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response(statusCode: $code, reasonPhrase: $reasonPhrase);
    }
}
