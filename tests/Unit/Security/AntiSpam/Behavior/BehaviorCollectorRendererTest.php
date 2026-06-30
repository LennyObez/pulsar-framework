<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Behavior;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Behavior\BehaviorCollectorRenderer;

use function str_contains;

#[CoversClass(BehaviorCollectorRenderer::class)]
final class BehaviorCollectorRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        BehaviorCollectorRenderer::resetGlobalInstance();
    }

    private function renderer(): BehaviorCollectorRenderer
    {
        return new BehaviorCollectorRenderer('pulsar-bx', '/_pulsar/anti-spam/behavior-collector.js');
    }

    #[Test]
    public function rendersAMarkedHiddenFieldAndSameOriginScript(): void
    {
        $html = $this->renderer()->render();

        self::assertStringContainsString('<input type="hidden"', $html);
        self::assertStringContainsString('name="pulsar-bx"', $html);
        // The collector finds its field by this marker.
        self::assertStringContainsString('data-pulsar-behavior', $html);
        self::assertStringContainsString('src="/_pulsar/anti-spam/behavior-collector.js"', $html);
        // No inline script body — CSP `script-src 'self'` clean.
        self::assertFalse(str_contains($html, 'javascript:'));
    }

    #[Test]
    public function stampsTheCspNonceOnTheScriptTag(): void
    {
        $html = $this->renderer()->render('nonce-xyz');

        self::assertStringContainsString('nonce="nonce-xyz"', $html);
    }

    #[Test]
    public function omitsNonceAttributeWhenNoNonce(): void
    {
        self::assertStringNotContainsString('nonce=', $this->renderer()->render());
    }

    #[Test]
    public function renderGlobalReturnsEmptyStringWhenDisabled(): void
    {
        self::assertSame('', BehaviorCollectorRenderer::renderGlobal());
    }

    #[Test]
    public function renderGlobalDelegatesToTheConfiguredInstance(): void
    {
        BehaviorCollectorRenderer::setGlobalInstance($this->renderer());

        self::assertStringContainsString('data-pulsar-behavior', BehaviorCollectorRenderer::renderGlobal());
    }
}
