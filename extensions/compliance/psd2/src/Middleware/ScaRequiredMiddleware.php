<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function str_contains;

/**
 * Middleware that enforces SCA for routes requiring strong authentication.
 *
 * Checks the request for a valid SCA verification token. If missing or
 * invalid, returns 403 with details on the required SCA flow.
 */
#[Api(since: '1.0.0')]
final readonly class ScaRequiredMiddleware implements MiddlewareInterface
{
    public const string REQUEST_ATTRIBUTE = '_psd2_sca_verified';
    public const string SCA_TOKEN_HEADER = 'X-PSD2-SCA-Token';

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scaVerified = $request->getAttribute(self::REQUEST_ATTRIBUTE);

        if ($scaVerified === true) {
            return $handler->handle($request);
        }

        $scaToken = $request->getHeaderLine(self::SCA_TOKEN_HEADER);

        if ($scaToken === '') {
            return $this->scaRequiredResponse($request);
        }

        // If a token is present, the SCA verification should have been
        // performed upstream and the attribute set. A token without
        // the attribute means verification was not completed.
        return $this->scaRequiredResponse($request);
    }

    private function scaRequiredResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                [
                    'error' => 'Strong Customer Authentication required',
                    'code' => 'sca_required',
                    'status' => 403,
                ],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: 'Strong Customer Authentication required',
        );
    }
}
