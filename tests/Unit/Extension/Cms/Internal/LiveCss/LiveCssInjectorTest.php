<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\LiveCss;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\LiveCss\LiveCssInjector;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverride;

#[CoversClass(LiveCssInjector::class)]
final class LiveCssInjectorTest extends TestCase
{
    #[Test]
    public function generateStyleBlockWithTokenOverrides(): void
    {
        $hashComputer = $this->createStub(CspHashComputerInterface::class);
        $injector = new LiveCssInjector($hashComputer);

        $override = $this->buildOverride(
            tokenOverrides: ['--primary-color' => '#ff0000', '--font-size' => '16px'],
            cssContent: '',
        );

        $result = $injector->generateStyleBlock($override);

        self::assertStringContainsString(':root {', $result);
        self::assertStringContainsString('--primary-color: #ff0000;', $result);
        self::assertStringContainsString('--font-size: 16px;', $result);
    }

    #[Test]
    public function generateStyleBlockWithCustomCss(): void
    {
        $hashComputer = $this->createStub(CspHashComputerInterface::class);
        $injector = new LiveCssInjector($hashComputer);

        $override = $this->buildOverride(
            tokenOverrides: [],
            cssContent: 'body { color: blue; }',
        );

        $result = $injector->generateStyleBlock($override);

        self::assertSame('body { color: blue; }', $result);
    }

    #[Test]
    public function generateStyleBlockCombinesTokensAndCss(): void
    {
        $hashComputer = $this->createStub(CspHashComputerInterface::class);
        $injector = new LiveCssInjector($hashComputer);

        $override = $this->buildOverride(
            tokenOverrides: ['--bg' => '#fff'],
            cssContent: 'body { margin: 0; }',
        );

        $result = $injector->generateStyleBlock($override);

        self::assertStringContainsString(':root {', $result);
        self::assertStringContainsString('--bg: #fff;', $result);
        self::assertStringContainsString('body { margin: 0; }', $result);
    }

    #[Test]
    public function generateStyleBlockReturnsEmptyForNoOverrides(): void
    {
        $hashComputer = $this->createStub(CspHashComputerInterface::class);
        $injector = new LiveCssInjector($hashComputer);

        $override = $this->buildOverride(tokenOverrides: [], cssContent: '');

        $result = $injector->generateStyleBlock($override);

        self::assertSame('', $result);
    }

    #[Test]
    public function generateStyleBlockTrimsWhitespaceCss(): void
    {
        $hashComputer = $this->createStub(CspHashComputerInterface::class);
        $injector = new LiveCssInjector($hashComputer);

        $override = $this->buildOverride(tokenOverrides: [], cssContent: '   ');

        $result = $injector->generateStyleBlock($override);

        self::assertSame('', $result);
    }

    #[Test]
    public function generateCspDirectiveIncludesHash(): void
    {
        $hashComputer = $this->createStub(CspHashComputerInterface::class);
        $hashComputer->method('computeHash')->willReturn('sha256-abc123');

        $injector = new LiveCssInjector($hashComputer);

        $result = $injector->generateCspDirective('body { color: red; }');

        self::assertSame("style-src 'self' 'sha256-abc123'", $result);
    }

    /**
     * @param array<string, string> $tokenOverrides
     */
    private function buildOverride(array $tokenOverrides, string $cssContent): CssOverride
    {
        return new CssOverride(
            id: 'override-1',
            tenantId: null,
            themeId: 'theme-1',
            version: 1,
            cssContent: $cssContent,
            cssHash: 'hash-1',
            tokenOverrides: $tokenOverrides,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            createdBy: 'user-1',
            reason: 'Test',
        );
    }
}
