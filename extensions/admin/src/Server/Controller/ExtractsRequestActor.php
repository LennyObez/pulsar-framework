<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Extracts the authenticated actor ID from a server request.
 *
 * Used by admin controllers that require an actor for MutationContext.
 */
#[Internal]
trait ExtractsRequestActor
{
    private function resolveActor(ServerRequestInterface $request): string
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        return $identity?->id() ?? 'anonymous';
    }
}
