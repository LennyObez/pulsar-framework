<?php

declare(strict_types=1);

namespace Pulsar\Auth\Guard;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Identity\IdentityInterface;

use function str_starts_with;
use function substr;
use function trim;

/**
 * Bearer token authentication guard.
 *
 * Extracts the token from the Authorization header and delegates
 * resolution to a TokenResolverInterface implementation.
 */
final readonly class TokenGuard implements GuardInterface
{
    public function __construct(
        private TokenResolverInterface $resolver,
    ) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?IdentityInterface
    {
        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '') {
            return null;
        }

        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        if ($token === '') {
            return null;
        }

        return $this->resolver->resolve($token);
    }

    #[Override]
    public function name(): string
    {
        return 'token';
    }
}
