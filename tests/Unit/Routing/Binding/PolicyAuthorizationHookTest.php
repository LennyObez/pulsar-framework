<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\PolicyAuthorizationHook;
use stdClass;

#[CoversClass(PolicyAuthorizationHook::class)]
final class PolicyAuthorizationHookTest extends TestCase
{
    #[Test]
    public function delegatesToGateWithCorrectPolicyContext(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $model = new stdClass();
        $meta = new BindingMeta(class: stdClass::class, authzPolicy: 'user.view');

        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())
            ->method('allows')
            ->with(
                $identity,
                'user.view',
                self::callback(static function (PolicyContext $ctx) use ($model): bool {
                    return $ctx->permission === 'user.view'
                        && $ctx->resource === stdClass::class
                        && $ctx->attributes === ['model' => $model];
                }),
            )
            ->willReturn(true);

        $hook = new PolicyAuthorizationHook($gate);

        self::assertTrue($hook->authorize($identity, $model, $meta));
    }

    #[Test]
    public function usesViewAsDefaultPermissionWhenAuthzPolicyIsNull(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $model = new stdClass();
        $meta = new BindingMeta(class: stdClass::class);

        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())
            ->method('allows')
            ->with(
                $identity,
                'view',
                self::callback(static fn(PolicyContext $ctx): bool => $ctx->permission === 'view'),
            )
            ->willReturn(true);

        $hook = new PolicyAuthorizationHook($gate);

        self::assertTrue($hook->authorize($identity, $model, $meta));
    }

    #[Test]
    public function returnsFalseWhenGateDenies(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $model = new stdClass();
        $meta = new BindingMeta(class: stdClass::class);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $hook = new PolicyAuthorizationHook($gate);

        self::assertFalse($hook->authorize($identity, $model, $meta));
    }

    #[Test]
    public function returnsTrueWhenGateAllows(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $model = new stdClass();
        $meta = new BindingMeta(class: stdClass::class, authzPolicy: 'admin.manage');

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(true);

        $hook = new PolicyAuthorizationHook($gate);

        self::assertTrue($hook->authorize($identity, $model, $meta));
    }
}
