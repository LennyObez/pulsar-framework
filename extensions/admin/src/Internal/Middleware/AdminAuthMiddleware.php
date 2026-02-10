<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Enforces authentication and role requirements for admin routes.
 */
#[Internal]
final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminAccessGate $accessGate,
        private readonly AdminConfig $config,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            return Response::json(
                ['error' => 'Authentication required'],
                ResponseStatus::Unauthorized,
            );
        }

        if ($this->config->security->require2fa) {
            $twoFaStatus = $identity->twoFactorStatus();
            if ($twoFaStatus !== TwoFactorStatus::Verified) {
                return Response::json(
                    ['error' => 'Two-factor authentication required for admin access'],
                    ResponseStatus::Forbidden,
                );
            }
        }

        try {
            $this->accessGate->assertCanAccess($identity);
        } catch (AdminAccessDeniedException $e) {
            return Response::json(
                ['error' => $e->getMessage()],
                ResponseStatus::Forbidden,
            );
        }

        return $next($request);
    }
}
