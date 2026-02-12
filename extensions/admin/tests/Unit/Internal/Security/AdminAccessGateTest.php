<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Security;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Domain\AdminPermission;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;

final class AdminAccessGateTest extends TestCase
{
    private function createGate(bool $policyResult): AdminAccessGate
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')->willReturn($policyResult);

        return new AdminAccessGate($policy);
    }

    private function createGateWithCallback(Closure $callback): AdminAccessGate
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')->willReturnCallback($callback);

        return new AdminAccessGate($policy);
    }

    #[Test]
    public function assert_can_access_passes_for_allowed_identity(): void
    {
        $gate = $this->createGate(true);
        $identity = new Identity('admin-1', 'Admin', ['admin']);

        $gate->assertCanAccess($identity);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assert_can_access_throws_for_denied_identity(): void
    {
        $gate = $this->createGate(false);
        $identity = new Identity('user-1', 'User', ['viewer']);

        $this->expectException(AdminAccessDeniedException::class);
        $gate->assertCanAccess($identity);
    }

    #[Test]
    public function assert_can_access_throws_for_anonymous(): void
    {
        $gate = $this->createGate(false);

        $this->expectException(AdminAccessDeniedException::class);
        $gate->assertCanAccess(new AnonymousIdentity());
    }

    #[Test]
    public function assert_can_perform_passes_for_allowed_operation(): void
    {
        $gate = $this->createGate(true);
        $identity = new Identity('admin-1', 'Admin', ['admin']);

        $gate->assertCanPerform($identity, 'users', ResourceOperation::List);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assert_can_perform_throws_for_denied_operation(): void
    {
        $gate = $this->createGate(false);
        $identity = new Identity('user-1', 'User', []);

        $this->expectException(AdminAccessDeniedException::class);
        $gate->assertCanPerform($identity, 'users', ResourceOperation::Delete);
    }

    #[Test]
    public function assert_can_perform_uses_manage_resources_for_crud_operations(): void
    {
        $capturedContexts = [];
        $gate = $this->createGateWithCallback(
            static function (IdentityInterface $identity, PolicyContext $context) use (&$capturedContexts): bool {
                $capturedContexts[] = $context;

                return true;
            },
        );

        $identity = new Identity('admin-1', 'Admin', ['admin']);

        $crudOps = [
            ResourceOperation::List,
            ResourceOperation::View,
            ResourceOperation::Create,
            ResourceOperation::Update,
            ResourceOperation::Delete,
            ResourceOperation::BulkAction,
        ];

        foreach ($crudOps as $op) {
            $gate->assertCanPerform($identity, 'posts', $op);
        }

        foreach ($capturedContexts as $ctx) {
            self::assertSame(AdminPermission::ManageResources->value, $ctx->permission);
        }
    }

    #[Test]
    public function assert_can_perform_uses_export_data_for_export(): void
    {
        $capturedContext = null;
        $gate = $this->createGateWithCallback(
            static function (IdentityInterface $identity, PolicyContext $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            },
        );

        $identity = new Identity('admin-1', 'Admin', ['admin']);
        $gate->assertCanPerform($identity, 'orders', ResourceOperation::Export);

        self::assertNotNull($capturedContext);
        self::assertSame(AdminPermission::ExportData->value, $capturedContext->permission);
        self::assertSame('orders', $capturedContext->resource);
    }

    #[Test]
    public function assert_can_perform_passes_operation_in_context_attributes(): void
    {
        $capturedContext = null;
        $gate = $this->createGateWithCallback(
            static function (IdentityInterface $identity, PolicyContext $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            },
        );

        $identity = new Identity('admin-1', 'Admin', ['admin']);
        $gate->assertCanPerform($identity, 'users', ResourceOperation::Create);

        self::assertNotNull($capturedContext);
        self::assertSame('create', $capturedContext->attributes['operation']);
    }
}
