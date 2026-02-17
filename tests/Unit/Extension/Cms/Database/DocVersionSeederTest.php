<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Database\DocVersionSeeder;

#[CoversClass(DocVersionSeeder::class)]
final class DocVersionSeederTest extends TestCase
{
    #[Test]
    public function definitionsReturnsTwoVersions(): void
    {
        $definitions = DocVersionSeeder::definitions();

        self::assertCount(2, $definitions);
    }

    #[Test]
    public function definitionsContainStableAndDevelopmentVersions(): void
    {
        $definitions = DocVersionSeeder::definitions();
        $slugs = array_map(static fn(array $d): string => $d['slug'], $definitions);

        self::assertContains('1.0', $slugs);
        self::assertContains('master', $slugs);
    }

    #[Test]
    public function stableVersionIsMarkedAsCurrent(): void
    {
        $definitions = DocVersionSeeder::definitions();
        $stable = null;

        foreach ($definitions as $def) {
            if ($def['slug'] === '1.0') {
                $stable = $def;
                break;
            }
        }

        self::assertNotNull($stable);
        self::assertTrue($stable['isCurrent']);
        self::assertSame('1.0 (Stable)', $stable['label']);
    }

    #[Test]
    public function masterVersionIsNotCurrent(): void
    {
        $definitions = DocVersionSeeder::definitions();
        $master = null;

        foreach ($definitions as $def) {
            if ($def['slug'] === 'master') {
                $master = $def;
                break;
            }
        }

        self::assertNotNull($master);
        self::assertFalse($master['isCurrent']);
        self::assertSame('Master (Development)', $master['label']);
    }

    #[Test]
    public function onlyOneVersionIsMarkedAsCurrent(): void
    {
        $definitions = DocVersionSeeder::definitions();
        $currentCount = 0;

        foreach ($definitions as $def) {
            if ($def['isCurrent']) {
                $currentCount++;
            }
        }

        self::assertSame(1, $currentCount, 'Exactly one version should be marked as current');
    }

    #[Test]
    public function eachDefinitionHasRequiredFields(): void
    {
        $definitions = DocVersionSeeder::definitions();

        foreach ($definitions as $definition) {
            self::assertArrayHasKey('slug', $definition);
            self::assertArrayHasKey('label', $definition);
            self::assertArrayHasKey('isCurrent', $definition);
            self::assertNotEmpty($definition['slug']);
            self::assertNotEmpty($definition['label']);
            self::assertIsBool($definition['isCurrent']);
        }
    }

    #[Test]
    public function runInsertsVersionsViaConnection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        /** @var list<array{0: string, 1: array<string, mixed>}> $executedQueries */
        $executedQueries = [];
        $connection->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$executedQueries): int {
                $executedQueries[] = [$sql, $params];
                return 1;
            });

        $seeder = new DocVersionSeeder();
        $seeder->run($connection);

        self::assertCount(2, $executedQueries);

        // Verify first insert is the stable version
        self::assertSame('1.0', $executedQueries[0][1]['slug']);
        self::assertSame('1.0 (Stable)', $executedQueries[0][1]['label']);
        self::assertTrue($executedQueries[0][1]['is_default']);

        // Verify second insert is the master version
        self::assertSame('master', $executedQueries[1][1]['slug']);
        self::assertSame('Master (Development)', $executedQueries[1][1]['label']);
        self::assertFalse($executedQueries[1][1]['is_default']);
    }
}
