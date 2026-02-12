<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Runtime\LeakFixtureFactory;

use function count;

#[CoversClass(LeakFixtureFactory::class)]
final class LeakFixtureFactoryTest extends TestCase
{
    #[Test]
    public function it_returns_non_empty_list(): void
    {
        $fixtures = LeakFixtureFactory::create();

        self::assertNotEmpty($fixtures);
    }

    #[Test]
    public function all_fixtures_are_server_request_instances(): void
    {
        $fixtures = LeakFixtureFactory::create();

        foreach ($fixtures as $fixture) {
            self::assertInstanceOf(ServerRequestInterface::class, $fixture);
        }
    }

    #[Test]
    public function fixtures_have_varied_paths(): void
    {
        $fixtures = LeakFixtureFactory::create();

        $paths = [];
        foreach ($fixtures as $fixture) {
            $paths[] = $fixture->getUri()->getPath();
        }

        $uniquePaths = array_unique($paths);
        self::assertGreaterThan(1, count($uniquePaths), 'Fixtures should have varied paths');
    }

    #[Test]
    public function fixtures_include_multiple_http_methods(): void
    {
        $fixtures = LeakFixtureFactory::create();

        $methods = [];
        foreach ($fixtures as $fixture) {
            $methods[] = $fixture->getMethod();
        }

        $uniqueMethods = array_unique($methods);
        self::assertGreaterThan(1, count($uniqueMethods), 'Fixtures should use multiple HTTP methods');
        self::assertContains('GET', $uniqueMethods);
        self::assertContains('POST', $uniqueMethods);
    }

    #[Test]
    public function fixtures_are_deterministic(): void
    {
        $first = LeakFixtureFactory::create();
        $second = LeakFixtureFactory::create();

        self::assertCount(count($first), $second);

        foreach ($first as $i => $fixture) {
            self::assertSame($fixture->getUri()->getPath(), $second[$i]->getUri()->getPath());
            self::assertSame($fixture->getMethod(), $second[$i]->getMethod());
            self::assertSame((string) $fixture->getBody(), (string) $second[$i]->getBody());
        }
    }
}
