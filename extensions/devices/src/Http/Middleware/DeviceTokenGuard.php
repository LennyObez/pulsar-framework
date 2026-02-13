<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function str_starts_with;
use function substr;
use function trim;

/**
 * PSR-15 middleware that authenticates requests via device API tokens.
 *
 * Extracts a Bearer token from the Authorization header, validates it
 * against the device registry, and populates user_id and device_id
 * request attributes on success. Returns 401 JSON on failure.
 */
#[Internal(reason: 'Device token authentication guard — implementation detail')]
final readonly class DeviceTokenGuard implements MiddlewareInterface
{
    private const string BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private DeviceService $deviceService,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '' || !str_starts_with($header, self::BEARER_PREFIX)) {
            return self::unauthorized('Missing or malformed Authorization header');
        }

        $rawToken = trim(substr($header, 7));

        if ($rawToken === '') {
            return self::unauthorized('Empty bearer token');
        }

        $device = $this->deviceService->authenticate($rawToken);

        if ($device === null) {
            return self::unauthorized('Invalid device token');
        }

        $request = $request
            ->withAttribute('user_id', $device->userId)
            ->withAttribute('device_id', $device->id);

        return $handler->handle($request);
    }

    private static function unauthorized(string $message): ResponseInterface
    {
        return Response::json(
            ['error' => $message],
            401,
        );
    }
}
