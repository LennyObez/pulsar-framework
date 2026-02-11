<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\GeneratedFile;
use Pulsar\Codegen\GeneratedFileSet;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\GeneratorInterface;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Console\Command\Make\CrudCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(CrudCommand::class)]
final class CrudCommandTest extends TestCase
{
    #[Test]
    public function itHasTheCorrectName(): void
    {
        $command = $this->createCommand([]);

        self::assertSame('make:crud', $command->name);
    }

    #[Test]
    public function itHasADescription(): void
    {
        $command = $this->createCommand([]);

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function itReturnsInvalidWhenEntityNameIsMissing(): void
    {
        $command = $this->createCommand([]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function itReturnsErrorWhenNoGeneratorsRegistered(): void
    {
        $command = $this->createCommand([]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('User');
        $input->method('getOption')->willReturnMap([
            ['path', 'src', 'src'],
            ['namespace', 'App', 'App'],
            ['skip', '', ''],
        ]);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Error->value, $command->execute($input, $output));
    }

    #[Test]
    public function itExecutesAllGenerators(): void
    {
        $gen1 = $this->createStub(GeneratorInterface::class);
        $gen1->method('generate')->willReturn(new GeneratedFileSet([
            new GeneratedFile('/project/src/Repository/UserRepository.php', 'content1'),
        ]));

        $gen2 = $this->createStub(GeneratorInterface::class);
        $gen2->method('generate')->willReturn(new GeneratedFileSet([
            new GeneratedFile('/project/src/Policy/UserPolicy.php', 'content2'),
        ]));

        $command = $this->createCommand([
            'repository' => $gen1,
            'policy' => $gen2,
        ]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('User');
        $input->method('getOption')->willReturnMap([
            ['path', 'src', 'src'],
            ['namespace', 'App', 'App'],
            ['skip', '', ''],
        ]);
        $input->method('hasOption')->willReturn(false);

        $writtenLines = [];
        $output = $this->createMock(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(
            static function (string $line) use (&$writtenLines): void {
                $writtenLines[] = $line;
            },
        );
        $output->expects(self::atLeastOnce())->method('success');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        // Verify both generators produced output
        $hasRepository = false;
        $hasPolicy = false;
        foreach ($writtenLines as $line) {
            if (str_contains($line, 'repository')) {
                $hasRepository = true;
            }
            if (str_contains($line, 'policy')) {
                $hasPolicy = true;
            }
        }
        self::assertTrue($hasRepository);
        self::assertTrue($hasPolicy);
    }

    #[Test]
    public function itSkipsSpecifiedGenerators(): void
    {
        $gen1 = $this->createMock(GeneratorInterface::class);
        $gen1->expects(self::once())->method('generate')->willReturn(new GeneratedFileSet([
            new GeneratedFile('/project/src/Repository/UserRepository.php', 'content1'),
        ]));

        $gen2 = $this->createMock(GeneratorInterface::class);
        $gen2->expects(self::never())->method('generate');

        $command = $this->createCommand([
            'repository' => $gen1,
            'admin' => $gen2,
        ]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('User');
        $input->method('getOption')->willReturnMap([
            ['path', 'src', 'src'],
            ['namespace', 'App', 'App'],
            ['skip', '', 'admin'],
        ]);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name !== 'force',
        );

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
    }

    #[Test]
    public function itSkipsMultipleGenerators(): void
    {
        $gen1 = $this->createMock(GeneratorInterface::class);
        $gen1->expects(self::never())->method('generate');

        $gen2 = $this->createMock(GeneratorInterface::class);
        $gen2->expects(self::never())->method('generate');

        $gen3 = $this->createMock(GeneratorInterface::class);
        $gen3->expects(self::once())->method('generate')->willReturn(new GeneratedFileSet([]));

        $command = $this->createCommand([
            'admin' => $gen1,
            'form' => $gen2,
            'policy' => $gen3,
        ]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('User');
        $input->method('getOption')->willReturnMap([
            ['path', 'src', 'src'],
            ['namespace', 'App', 'App'],
            ['skip', '', 'admin,form'],
        ]);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name !== 'force',
        );

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
    }

    #[Test]
    public function itPropagatesForceFlag(): void
    {
        $receivedConfig = null;
        $gen = $this->createStub(GeneratorInterface::class);
        $gen->method('generate')->willReturnCallback(
            static function (EntityDefinition $entity, GeneratorConfig $config) use (&$receivedConfig): GeneratedFileSet {
                $receivedConfig = $config;
                return new GeneratedFileSet([]);
            },
        );

        $command = $this->createCommand(['repository' => $gen]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('User');
        $input->method('getOption')->willReturnMap([
            ['path', 'src', 'src'],
            ['namespace', 'App', 'App'],
            ['skip', '', ''],
        ]);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name === 'force',
        );

        $output = $this->createStub(OutputInterface::class);

        $command->execute($input, $output);

        self::assertNotNull($receivedConfig);
        self::assertTrue($receivedConfig->force);
    }

    #[Test]
    public function itHandlesGeneratorsWithNoOutput(): void
    {
        $gen = $this->createStub(GeneratorInterface::class);
        $gen->method('generate')->willReturn(new GeneratedFileSet([]));

        $command = $this->createCommand(['empty' => $gen]);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('User');
        $input->method('getOption')->willReturnMap([
            ['path', 'src', 'src'],
            ['namespace', 'App', 'App'],
            ['skip', '', ''],
        ]);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('success');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
    }

    /**
     * @param array<string, GeneratorInterface> $generators
     */
    private function createCommand(array $generators): CrudCommand
    {
        $entity = new EntityDefinition(
            className: 'User',
            namespace: 'App\\Entity',
            tableName: 'users',
            properties: [
                new PropertyDefinition(
                    name: 'id',
                    phpType: 'int',
                    columnName: 'id',
                    columnType: 'bigint',
                    nullable: false,
                    hasDefault: false,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: true,
                    isSortable: true,
                    length: null,
                    isPrimaryKey: true,
                ),
            ],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        return new CrudCommand(
            generators: $generators,
            entity: $entity,
        );
    }
}
