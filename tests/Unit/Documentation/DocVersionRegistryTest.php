<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Documentation\DocVersion;
use Pulsar\Documentation\DocVersionRegistry;

#[CoversClass(DocVersionRegistry::class)]
#[CoversClass(DocVersion::class)]
final class DocVersionRegistryTest extends TestCase
{
    private DocVersionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new DocVersionRegistry();
    }

    public function testRegisterAndGet(): void
    {
        $v = new DocVersion('1.0', 'v1.0', '/docs/1.0');
        $this->registry->register($v);

        self::assertSame($v, $this->registry->get('1.0'));
        self::assertTrue($this->registry->has('1.0'));
        self::assertNull($this->registry->get('2.0'));
        self::assertFalse($this->registry->has('2.0'));
    }

    public function testLatestReturnsExplicitLatest(): void
    {
        $this->registry->register(new DocVersion('1.0', 'v1.0', '/docs/1.0'));
        $this->registry->register(new DocVersion('1.1', 'v1.1', '/docs/1.1', isLatest: true));
        $this->registry->register(new DocVersion('2.0-beta', 'v2.0-beta', '/docs/2.0-beta', isPrerelease: true));

        $latest = $this->registry->latest();
        self::assertSame('1.1', $latest->version);
    }

    public function testLatestFallsBackToHighestStable(): void
    {
        $this->registry->register(new DocVersion('1.0', 'v1.0', '/docs/1.0'));
        $this->registry->register(new DocVersion('1.1', 'v1.1', '/docs/1.1'));

        $latest = $this->registry->latest();
        self::assertSame('1.1', $latest->version);
    }

    public function testLatestSkipsPrerelease(): void
    {
        $this->registry->register(new DocVersion('1.0', 'v1.0', '/docs/1.0'));
        $this->registry->register(new DocVersion('2.0-alpha', 'v2.0-alpha', '/docs/2.0', isPrerelease: true));

        $latest = $this->registry->latest();
        self::assertSame('1.0', $latest->version);
    }

    public function testResolveExistingVersion(): void
    {
        $v = new DocVersion('1.0', 'v1.0', '/docs/1.0', isLatest: true);
        $this->registry->register($v);

        self::assertSame($v, $this->registry->resolve('1.0'));
    }

    public function testResolveFallsBackToLatest(): void
    {
        $v = new DocVersion('1.0', 'v1.0', '/docs/1.0', isLatest: true);
        $this->registry->register($v);

        $resolved = $this->registry->resolve('99.99');
        self::assertSame('1.0', $resolved->version);
    }

    public function testAllReturnsSortedNewestFirst(): void
    {
        $this->registry->register(new DocVersion('1.0', 'v1.0', '/docs/1.0'));
        $this->registry->register(new DocVersion('2.0', 'v2.0', '/docs/2.0'));
        $this->registry->register(new DocVersion('1.5', 'v1.5', '/docs/1.5'));

        $all = $this->registry->all();
        self::assertCount(3, $all);
        self::assertSame('2.0', $all[0]->version);
        self::assertSame('1.5', $all[1]->version);
        self::assertSame('1.0', $all[2]->version);
    }

    public function testCount(): void
    {
        self::assertSame(0, $this->registry->count());

        $this->registry->register(new DocVersion('1.0', 'v1.0', '/docs/1.0'));
        self::assertSame(1, $this->registry->count());
    }

    public function testDocVersionUrlPrefix(): void
    {
        $v = new DocVersion('1.0', 'v1.0', '/docs/1.0');
        self::assertSame('/docs/1.0', $v->urlPrefix());
    }
}
