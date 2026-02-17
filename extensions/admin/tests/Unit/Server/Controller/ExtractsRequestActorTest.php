<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Server\Controller\ExtractsRequestActor;

#[CoversClass(ExtractsRequestActor::class)]
final class ExtractsRequestActorTest extends TestCase
{
    #[Test]
    public function resolves_actor_from_identity_attribute(): void
    {
        $harness = new class {
            use ExtractsRequestActor;

            public function actor(ServerRequestInterface $request): string
            {
                return $this->resolveActor($request);
            }
        };

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('user-42');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);

        self::assertSame('user-42', $harness->actor($request));
    }

    #[Test]
    public function returns_anonymous_when_no_identity(): void
    {
        $harness = new class {
            use ExtractsRequestActor;

            public function actor(ServerRequestInterface $request): string
            {
                return $this->resolveActor($request);
            }
        };

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        self::assertSame('anonymous', $harness->actor($request));
    }
}
