<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Htmx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Htmx\HtmxRequest;

#[CoversClass(HtmxRequest::class)]
final class HtmxRequestTest extends TestCase
{
    #[Test]
    public function detects_htmx_request(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('hasHeader')->willReturnCallback(
            fn(string $name) => $name === 'PX-Request',
        );

        $htmx = new HtmxRequest($psr);

        self::assertTrue($htmx->isHtmx());
    }

    #[Test]
    public function not_htmx_when_header_missing(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('hasHeader')->willReturn(false);

        $htmx = new HtmxRequest($psr);

        self::assertFalse($htmx->isHtmx());
    }

    #[Test]
    public function detects_history_restore(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-History-Restore-Request' ? 'true' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertTrue($htmx->isHistoryRestore());
    }

    #[Test]
    public function detects_boosted(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-Boosted' ? 'true' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertTrue($htmx->isBoosted());
    }

    #[Test]
    public function returns_trigger(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-Trigger' ? 'btn-submit' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertSame('btn-submit', $htmx->trigger());
    }

    #[Test]
    public function trigger_returns_null_when_absent(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturn('');

        $htmx = new HtmxRequest($psr);

        self::assertNull($htmx->trigger());
    }

    #[Test]
    public function returns_trigger_name(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-Trigger-Name' ? 'search' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertSame('search', $htmx->triggerName());
    }

    #[Test]
    public function returns_target(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-Target' ? 'results' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertSame('results', $htmx->target());
    }

    #[Test]
    public function returns_current_url(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-Current-URL' ? 'https://example.com/page' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertSame('https://example.com/page', $htmx->currentUrl());
    }

    #[Test]
    public function returns_prompt(): void
    {
        $psr = $this->createStub(ServerRequestInterface::class);
        $psr->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => $name === 'PX-Prompt' ? 'user input' : '',
        );

        $htmx = new HtmxRequest($psr);

        self::assertSame('user input', $htmx->prompt());
    }
}
