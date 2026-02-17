<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

#[CoversClass(SignalContext::class)]
final class SignalContextTest extends TestCase
{
    private ServerRequestInterface&Stub $request;

    protected function setUp(): void
    {
        $this->request = $this->createStub(ServerRequestInterface::class);
    }

    #[Test]
    public function storesAllProperties(): void
    {
        $context = new SignalContext(
            request: $this->request,
            sessionId: 'sess-abc',
            identityId: 'user-42',
            attributes: ['ip' => '192.168.1.1', 'region' => 'eu-west'],
        );

        self::assertSame($this->request, $context->request);
        self::assertSame('sess-abc', $context->sessionId);
        self::assertSame('user-42', $context->identityId);
        self::assertSame(['ip' => '192.168.1.1', 'region' => 'eu-west'], $context->attributes);
    }

    #[Test]
    public function defaultsToEmptySessionAndIdentity(): void
    {
        $context = new SignalContext(request: $this->request);

        self::assertSame('', $context->sessionId);
        self::assertSame('', $context->identityId);
        self::assertSame([], $context->attributes);
    }

    #[Test]
    public function attributeReturnsValueWhenPresent(): void
    {
        $context = new SignalContext(
            request: $this->request,
            attributes: ['tenant' => 'acme-corp'],
        );

        self::assertSame('acme-corp', $context->attribute('tenant'));
    }

    #[Test]
    public function attributeReturnsDefaultWhenMissing(): void
    {
        $context = new SignalContext(request: $this->request);

        self::assertNull($context->attribute('nonexistent'));
        self::assertSame('fallback', $context->attribute('nonexistent', 'fallback'));
    }

    #[Test]
    public function attributeReturnsFalseyValuesCorrectly(): void
    {
        $context = new SignalContext(
            request: $this->request,
            attributes: ['zero' => 0, 'empty' => '', 'false' => false],
        );

        // ?? only triggers on null/missing, so falsey non-null values are returned correctly
        self::assertSame(0, $context->attribute('zero', 999));
        self::assertSame('', $context->attribute('empty', 'fallback'));
        self::assertFalse($context->attribute('false', true));
    }
}
