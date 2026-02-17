<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigRepository;
use Pulsar\Console\Command\DebugConfigCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(DebugConfigCommand::class)]
final class DebugConfigCommandTest extends TestCase
{
    #[Test]
    public function it_has_correct_name(): void
    {
        $repository = new ConfigRepository();
        $command = new DebugConfigCommand($repository, []);

        self::assertSame('debug:config', $command->name);
    }

    #[Test]
    public function it_shows_no_config_message_when_repository_is_empty(): void
    {
        $repository = new ConfigRepository();
        $command = new DebugConfigCommand($repository, []);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('No configuration', $output->buffer);
    }

    #[Test]
    public function it_displays_config_dto_properties(): void
    {
        $dto = new DebugTestConfig(name: 'Pulsar', debug: true, port: 8080);
        $repository = new ConfigRepository();
        $repository->set($dto);

        $command = new DebugConfigCommand($repository, [DebugTestConfig::class]);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('DebugTestConfig', $output->buffer);
        self::assertStringContainsString('name', $output->buffer);
        self::assertStringContainsString('Pulsar', $output->buffer);
        self::assertStringContainsString('debug', $output->buffer);
        self::assertStringContainsString('true', $output->buffer);
    }

    #[Test]
    public function it_filters_by_property_name(): void
    {
        $dto = new DebugTestConfig(name: 'Pulsar', debug: false, port: 8080);
        $repository = new ConfigRepository();
        $repository->set($dto);

        $command = new DebugConfigCommand($repository, [DebugTestConfig::class]);

        $input = new ArrayInput(arguments: [], options: ['filter' => 'port']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('port', $output->buffer);
        self::assertStringContainsString('8080', $output->buffer);
    }

    #[Test]
    public function it_skips_unloaded_config_classes(): void
    {
        $repository = new ConfigRepository();

        /** @var list<class-string> $classes */
        $classes = ['NonExistent\\Config']; // @phpstan-ignore varTag.nativeType
        $command = new DebugConfigCommand($repository, $classes);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('No configuration', $output->buffer);
    }

    #[Test]
    public function it_formats_various_value_types(): void
    {
        $dto = new DebugTypesConfig(
            nullVal: null,
            boolVal: false,
            longString: str_repeat('x', 100),
            arrayVal: [1, 2, 3],
            enumVal: DebugTestEnum::Active,
        );

        $repository = new ConfigRepository();
        $repository->set($dto);

        $command = new DebugConfigCommand($repository, [DebugTypesConfig::class]);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('null', $output->buffer);
        self::assertStringContainsString('false', $output->buffer);
        self::assertStringContainsString('...', $output->buffer); // Truncated string
        self::assertStringContainsString('array(3)', $output->buffer);
        self::assertStringContainsString('Active', $output->buffer);
    }
}

/**
 * Test double for config DTO.
 */
final readonly class DebugTestConfig
{
    public function __construct(
        public string $name,
        public bool $debug,
        public int $port,
    ) {}
}

/**
 * Test double for various value types.
 */
final readonly class DebugTypesConfig
{
    public function __construct(
        public ?string $nullVal,
        public bool $boolVal,
        public string $longString,
        /** @var list<int> */
        public array $arrayVal,
        public DebugTestEnum $enumVal,
    ) {}
}

enum DebugTestEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
