<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Middleware;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Authentication and authorization guard for forum endpoints.
 *
 * Requires a valid identity for write operations. Read operations are allowed
 * for guests when allowGuestViewing is enabled in the forum config.
 * Checks the forum.access permission via GateInterface when a gate is available.
 *
 * @psalm-api Registered with the forum route middleware stack at extension
 *            boot; container-resolved by class-name.
 */
#[Internal(reason: 'Forum auth middleware; implementation detail')]
final readonly class ForumAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ForumConfig $config,
        private ?GateInterface $gate = null,
        private ?ForumProfileRepositoryInterface $profiles = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $isReadOnly = $method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS';

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($isReadOnly && $this->config->allowGuestViewing) {
            return $handler->handle($request);
        }

        if ($identity === null || !$identity->isAuthenticated()) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        if ($this->gate !== null && $this->gate->denies($identity, 'forum.access')) {
            return Response::json(['error' => 'Forum access denied'], 403);
        }

        if ($this->profiles !== null) {
            $profile = $this->profiles->findByUser($identity->id());

            if ($profile !== null && $profile->isBanned) {
                if ($profile->banExpiresAt !== null && $profile->banExpiresAt <= new DateTimeImmutable()) {
                    // Ban has expired; auto-clear so subsequent requests are not blocked
                    $this->profiles->clearBanFlag($profile->userId);
                } else {
                    return Response::json(['error' => 'You are banned from the forum', 'reason' => $profile->banReason], 403);
                }
            }
        }

        return $handler->handle($request);
    }
}
