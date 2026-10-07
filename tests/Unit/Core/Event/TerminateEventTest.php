<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\Event\TerminateEvent;
use ReflectionClass;

#[CoversClass(TerminateEvent::class)]
final class TerminateEventTest extends TestCase
{
    #[Test]
    public function constructorStoresRequestAndResponse(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $event = new TerminateEvent($request, $response);

        self::assertSame($request, $event->request);
        self::assertSame($response, $event->response);
    }

    #[Test]
    public function eventIsReadonly(): void
    {
        $reflection = new ReflectionClass(TerminateEvent::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
