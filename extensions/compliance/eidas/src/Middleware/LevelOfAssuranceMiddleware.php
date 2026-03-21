<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Eidas\Domain\LevelOfAssurance;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function str_contains;

/**
 * Middleware enforcing minimum Level of Assurance per eIDAS Art. 8.
 *
 * Checks the request for a LoA attribute set by the authentication layer.
 * If the actual LoA does not meet the configured minimum, returns 403.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LevelOfAssuranceMiddleware implements MiddlewareInterface
{
    public const string LOA_ATTRIBUTE = '_eidas_level_of_assurance';

    public function __construct(
        private LevelOfAssurance $minimumLevel,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LevelOfAssurance|null $actualLevel */
        $actualLevel = $request->getAttribute(self::LOA_ATTRIBUTE);

        if ($actualLevel === null) {
            return $this->forbiddenResponse($request, 'No assurance level established');
        }

        if (!$actualLevel->meetsMinimum($this->minimumLevel)) {
            return $this->forbiddenResponse(
                $request,
                'Insufficient assurance level: required ' . $this->minimumLevel->value . ', actual ' . $actualLevel->value,
            );
        }

        return $handler->handle($request);
    }

    private function forbiddenResponse(ServerRequestInterface $request, string $reason): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                [
                    'error' => $reason,
                    'code' => 'insufficient_assurance_level',
                    'required' => $this->minimumLevel->value,
                    'status' => 403,
                ],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: $reason,
        );
    }
}
