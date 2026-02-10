<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\AuthorizationDenied;
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;

#[CoversClass(Gate::class)]
final class GateEventDispatchTest extends TestCase
{
    private InMemoryRoleRegistry $registry;

    /** @var list<EventEnvelope> */
    private array $dispatchedEnvelopes = [];

    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRoleRegistry();
        $this->dispatchedEnvelopes = [];

        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->dispatcher->method('dispatchEnvelope')
            ->willReturnCallback(function (EventEnvelope $envelope): EventEnvelope {
                $this->dispatchedEnvelopes[] = $envelope;
                return $envelope;
            });
    }

    #[Test]
    public function gateWorksWithoutEventDispatcher(): void
    {
        $role = new Role('editor', [new Permission('posts.create')]);
        $this->registry->register($role);

        $gate = new Gate($this->registry);
        $identity = new Identity(id: 'user-1', displayName: 'Test', roles: ['editor']);

        self::assertTrue($gate->allows($identity, 'posts.create'));
    }

    #[Test]
    public function gateDispatchesGrantedEventOnRbacAllow(): void
    {
        $role = new Role('editor', [new Permission('posts.create')]);
        $this->registry->register($role);

        $gate = new Gate($this->registry, eventDispatcher: $this->dispatcher);
        $identity = new Identity(id: 'user-1', displayName: 'Test', roles: ['editor']);

        $gate->allows($identity, 'posts.create');

        self::assertCount(1, $this->dispatchedEnvelopes);
        self::assertSame(AuthorizationGranted::class, $this->dispatchedEnvelopes[0]->eventType);
        self::assertSame('RBAC', $this->dispatchedEnvelopes[0]->payload['grant_reason']);
        self::assertSame('user-1', $this->dispatchedEnvelopes[0]->payload['identity_id']);
        self::assertSame('posts.create', $this->dispatchedEnvelopes[0]->payload['permission']);
    }

    #[Test]
    public function gateDispatchesGrantedEventOnSuperRole(): void
    {
        $gate = new Gate($this->registry, superRoles: ['superadmin'], eventDispatcher: $this->dispatcher);
        $identity = new Identity(id: 'admin-1', displayName: 'Super', roles: ['superadmin']);

        $gate->allows($identity, 'anything');

        self::assertCount(1, $this->dispatchedEnvelopes);
        self::assertSame(AuthorizationGranted::class, $this->dispatchedEnvelopes[0]->eventType);
        self::assertSame('super-role', $this->dispatchedEnvelopes[0]->payload['grant_reason']);
    }

    #[Test]
    public function gateDispatchesDeniedEventOnAbacDeny(): void
    {
        $role = new Role('editor', [new Permission('posts.delete')]);
        $this->registry->register($role);

        $denyPolicy = $this->createStub(PolicyInterface::class);
        $denyPolicy->method('evaluate')->willReturn(false);

        $gate = new Gate($this->registry, eventDispatcher: $this->dispatcher);
        $gate->addPolicy($denyPolicy);

        $identity = new Identity(id: 'user-2', displayName: 'Editor', roles: ['editor']);

        $gate->allows($identity, 'posts.delete');

        self::assertCount(1, $this->dispatchedEnvelopes);
        self::assertSame(AuthorizationDenied::class, $this->dispatchedEnvelopes[0]->eventType);
        self::assertSame('ABAC-deny', $this->dispatchedEnvelopes[0]->payload['denial_reason']);
    }

    #[Test]
    public function gateDispatchesDeniedEventOnDefaultDeny(): void
    {
        $gate = new Gate($this->registry, eventDispatcher: $this->dispatcher);
        $identity = new Identity(id: 'user-3', displayName: 'NoPerms', roles: []);

        $gate->allows($identity, 'admin.panel');

        self::assertCount(1, $this->dispatchedEnvelopes);
        self::assertSame(AuthorizationDenied::class, $this->dispatchedEnvelopes[0]->eventType);
        self::assertSame('default-deny', $this->dispatchedEnvelopes[0]->payload['denial_reason']);
    }

    #[Test]
    public function gateDispatchesGrantedEventOnAbacAllow(): void
    {
        $allowPolicy = $this->createStub(PolicyInterface::class);
        $allowPolicy->method('evaluate')->willReturn(true);

        $gate = new Gate($this->registry, eventDispatcher: $this->dispatcher);
        $gate->addPolicy($allowPolicy);

        $identity = new Identity(id: 'user-4', displayName: 'Special', roles: []);

        $gate->allows($identity, 'special.access');

        self::assertCount(1, $this->dispatchedEnvelopes);
        self::assertSame(AuthorizationGranted::class, $this->dispatchedEnvelopes[0]->eventType);
        self::assertSame('ABAC', $this->dispatchedEnvelopes[0]->payload['grant_reason']);
    }

    #[Test]
    public function envelopeCarriesCorrelationId(): void
    {
        $role = new Role('editor', [new Permission('posts.create')]);
        $this->registry->register($role);

        $gate = new Gate($this->registry, eventDispatcher: $this->dispatcher);
        $identity = new Identity(id: 'user-1', displayName: 'Test', roles: ['editor']);

        $gate->allows($identity, 'posts.create');

        self::assertCount(1, $this->dispatchedEnvelopes);

        $envelope = $this->dispatchedEnvelopes[0];
        self::assertNotEmpty($envelope->metadata->correlationId->value);
        self::assertNotEmpty($envelope->payload['correlation_id']);
    }

    #[Test]
    public function gateDispatchesEventWithResourceFromPolicyContext(): void
    {
        $role = new Role('editor', [new Permission('posts.create')]);
        $this->registry->register($role);

        $gate = new Gate($this->registry, eventDispatcher: $this->dispatcher);
        $identity = new Identity(id: 'user-1', displayName: 'Test', roles: ['editor']);

        $context = new PolicyContext(permission: 'posts.create', resource: 'post-42');
        $gate->allows($identity, 'posts.create', $context);

        self::assertCount(1, $this->dispatchedEnvelopes);
        self::assertSame('post-42', $this->dispatchedEnvelopes[0]->payload['resource']);
    }
}
