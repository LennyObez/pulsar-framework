<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Introspection\Internal\SnapshotFileReader;
use Pulsar\Introspection\MetadataContributorInterface;
use Pulsar\Introspection\ProjectMetadataBuilder;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(ProjectMetadataService::class)]
final class ProjectMetadataServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_service_test_' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function makeProbe(): CoreRuntimeProbe
    {
        $container = self::createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([]);

        return new CoreRuntimeProbe(
            container: $container,
            extensionProber: null,
            router: null,
            commandProber: null,
        );
    }

    private function makeReflector(): ConfigSchemaReflector
    {
        return new ConfigSchemaReflector(new SensitiveDataScrubber());
    }

    private function makeReader(): SnapshotFileReader
    {
        return new SnapshotFileReader($this->tempDir);
    }

    #[Test]
    public function snapshotAggregatesAllSections(): void
    {
        $contributor = self::createStub(MetadataContributorInterface::class);
        $contributor->method('id')->willReturn('test-contributor');
        $contributor->method('contribute')->willReturnCallback(
            static function (ProjectMetadataBuilder $builder): void {
                $builder->addSection('info', ['name' => 'test']);
            },
        );

        $service = new ProjectMetadataService(
            probe: $this->makeProbe(),
            schemaReflector: $this->makeReflector(),
            snapshotReader: $this->makeReader(),
            scrubber: new SensitiveDataScrubber(),
            contributors: [$contributor],
            configClasses: [],
        );

        $snapshot = $service->snapshot();

        self::assertArrayHasKey('test-contributor', $snapshot->contributions);
        self::assertSame('test', $snapshot->contributions['test-contributor']->sections['info']['name']);
        self::assertSame([], $snapshot->architectureMap->extensions);
        self::assertSame([], $snapshot->routeMap->routes);
        self::assertSame([], $snapshot->commandReference->commands);
        self::assertSame([], $snapshot->configSchema->schemas);
        self::assertSame([], $snapshot->apiSnapshot->classes);
    }

    #[Test]
    public function snapshotIsMemoized(): void
    {
        $service = new ProjectMetadataService(
            probe: $this->makeProbe(),
            schemaReflector: $this->makeReflector(),
            snapshotReader: $this->makeReader(),
            scrubber: new SensitiveDataScrubber(),
            contributors: [],
            configClasses: [],
        );

        $first = $service->snapshot();
        $second = $service->snapshot();

        self::assertSame($first, $second);
    }

    #[Test]
    public function snapshotPropagatesWarnings(): void
    {
        $contributor = self::createStub(MetadataContributorInterface::class);
        $contributor->method('id')->willReturn('warn-contributor');
        $contributor->method('contribute')->willReturnCallback(
            static function (ProjectMetadataBuilder $builder): void {
                $builder->addSection('data', ['a' => 1]);
                $builder->addSection('data', ['a' => 2]);
            },
        );

        $service = new ProjectMetadataService(
            probe: $this->makeProbe(),
            schemaReflector: $this->makeReflector(),
            snapshotReader: $this->makeReader(),
            scrubber: new SensitiveDataScrubber(),
            contributors: [$contributor],
            configClasses: [],
        );

        $snapshot = $service->snapshot();

        self::assertNotEmpty($snapshot->warnings);

        $warningText = implode(' | ', $snapshot->warnings);

        // Warnings from probe (null router/registry/app)
        self::assertStringContainsString('not available', $warningText);

        // Warnings from contributor (duplicate key)
        self::assertStringContainsString('already exists, overwriting', $warningText);
    }
}
