<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\MetadataExportCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\ContainerInterface;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Introspection\Internal\SnapshotFileReader;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\RouterInterface;

use function json_decode;

#[CoversClass(MetadataExportCommand::class)]
final class MetadataExportCommandTest extends TestCase
{
    private ProjectMetadataService $metadataService;
    private SensitiveDataScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new SensitiveDataScrubber();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([]);
        $container->method('getInstances')->willReturn([]);

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([]);

        $probe = new CoreRuntimeProbe($container, null, $router, null);
        $reflector = new ConfigSchemaReflector($this->scrubber);
        $snapshotReader = new SnapshotFileReader('/nonexistent');

        $this->metadataService = new ProjectMetadataService(
            probe: $probe,
            schemaReflector: $reflector,
            snapshotReader: $snapshotReader,
            scrubber: $this->scrubber,
            contributors: [],
            configClasses: [],
        );
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new MetadataExportCommand($this->metadataService, $this->scrubber);

        self::assertSame('metadata:export', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function exportsAllSections(): void
    {
        $command = new MetadataExportCommand($this->metadataService, $this->scrubber);
        $output = new BufferedOutput();
        $input = new ArrayInput('metadata:export');

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output->buffer, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('framework_version', $decoded);
        self::assertArrayHasKey('api_snapshot', $decoded);
        self::assertArrayHasKey('architecture_map', $decoded);
        self::assertArrayHasKey('config_schema', $decoded);
        self::assertArrayHasKey('command_reference', $decoded);
        self::assertArrayHasKey('route_map', $decoded);
    }

    #[Test]
    public function invalidSectionReturnsInvalid(): void
    {
        $command = new MetadataExportCommand($this->metadataService, $this->scrubber);
        $output = new BufferedOutput();
        $input = new ArrayInput('metadata:export', [], ['section' => 'nonexistent']);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('Invalid section', $output->errorBuffer);
    }

    #[Test]
    public function specificSectionExtractsCorrectly(): void
    {
        $command = new MetadataExportCommand($this->metadataService, $this->scrubber);
        $output = new BufferedOutput();
        $input = new ArrayInput('metadata:export', [], ['section' => 'api']);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output->buffer, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('api_snapshot', $decoded);
        self::assertArrayNotHasKey('architecture_map', $decoded);
    }

    #[Test]
    public function warningsWrittenToStderr(): void
    {
        // The SnapshotFileReader with /nonexistent path will produce a warning
        // about missing API snapshot file
        $command = new MetadataExportCommand($this->metadataService, $this->scrubber);
        $output = new BufferedOutput();
        $input = new ArrayInput('metadata:export');

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[warning]', $output->errorBuffer);
    }

    #[Test]
    public function prettyPrintFormatsOutput(): void
    {
        $command = new MetadataExportCommand($this->metadataService, $this->scrubber);
        $output = new BufferedOutput();
        $input = new ArrayInput('metadata:export', [], ['pretty' => true]);

        $exit = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString("\n", $output->buffer);
        self::assertStringContainsString('    ', $output->buffer);
    }
}
