<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;

/**
 * Default authorization hook that delegates to the Gate.
 *
 * Builds a PolicyContext with the 'view' permission and the resolved
 * model, then asks the Gate whether the identity is allowed.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Default implementation; consumers may provide their own AuthorizationHookInterface')]
final readonly class PolicyAuthorizationHook implements AuthorizationHookInterface
{
    public function __construct(
        private GateInterface $gate,
    ) {}

    #[Override]
    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        $permission = $meta->authzPolicy ?? 'view';

        $context = new PolicyContext(
            permission: $permission,
            resource: $meta->class,
            attributes: ['model' => $model],
        );

        return $this->gate->allows($identity, $permission, $context);
    }
}
