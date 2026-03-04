<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\DevReloadDirective;

#[CoversClass(DevReloadDirective::class)]
final class DevReloadDirectiveTest extends TestCase
{
    private DevReloadDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new DevReloadDirective();
    }

    #[Test]
    public function nameReturnsDevReload(): void
    {
        self::assertSame('dev_reload', $this->directive->name());
    }

    #[Test]
    public function compileChecksEnvironmentMode(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('__env_mode', $result);
        self::assertStringContainsString('production', $result);
    }

    #[Test]
    public function compileInjectsWebSocketScript(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('WebSocket', $result);
        self::assertStringContainsString('__pulse/reload', $result);
    }

    #[Test]
    public function compileUsesSecureWebSocket(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('wss:', $result);
    }

    #[Test]
    public function compileIncludesCspNonceSupport(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('__csp_nonce', $result);
        self::assertStringContainsString('nonce=', $result);
    }

    #[Test]
    public function compileHandlesAutoReconnect(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('onclose', $result);
        self::assertStringContainsString('setTimeout', $result);
    }
}
