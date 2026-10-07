<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Input;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Input\ArgvInput;

#[CoversClass(ArgvInput::class)]
final class ArgvInputTest extends TestCase
{
    #[Test]
    public function parsesCommandName(): void
    {
        $input = new ArgvInput(['pulsar', 'migrate:run']);

        self::assertSame('migrate:run', $input->commandName);
    }

    #[Test]
    public function parsesLongOptionWithValue(): void
    {
        $input = new ArgvInput(['pulsar', 'build', '--env=production']);

        self::assertSame('build', $input->commandName);
        self::assertTrue($input->hasOption('env'));
        self::assertSame('production', $input->getOption('env'));
    }

    #[Test]
    public function parsesLongBooleanOption(): void
    {
        $input = new ArgvInput(['pulsar', 'serve', '--verbose']);

        self::assertTrue($input->hasOption('verbose'));
        self::assertTrue($input->getOption('verbose'));
    }

    #[Test]
    public function parsesShortBooleanFlags(): void
    {
        $input = new ArgvInput(['pulsar', 'test', '-vvv']);

        self::assertTrue($input->hasOption('v'));
    }

    #[Test]
    public function parsesShortOptionWithEquals(): void
    {
        $input = new ArgvInput(['pulsar', 'deploy', '-e=staging']);

        self::assertSame('staging', $input->getOption('e'));
    }

    #[Test]
    public function parsesPositionalArguments(): void
    {
        $input = new ArgvInput(['pulsar', 'make:module', 'Payments', 'Banking']);

        self::assertSame('make:module', $input->commandName);
        self::assertSame('Payments', $input->getArgument(0));
        self::assertSame('Banking', $input->getArgument(1));
    }

    #[Test]
    public function getArgumentReturnsDefaultForMissing(): void
    {
        $input = new ArgvInput(['pulsar', 'list']);

        self::assertNull($input->getArgument(0));
        self::assertSame('fallback', $input->getArgument(0, 'fallback'));
    }

    #[Test]
    public function getArgumentWithStringKeyReturnsDefault(): void
    {
        $input = new ArgvInput(['pulsar', 'list', 'value']);

        self::assertSame('fallback', $input->getArgument('named', 'fallback'));
    }

    #[Test]
    public function getOptionReturnsDefaultForMissing(): void
    {
        $input = new ArgvInput(['pulsar', 'list']);

        self::assertFalse($input->hasOption('format'));
        self::assertSame('json', $input->getOption('format', 'json'));
    }

    #[Test]
    public function tokensArePreservedAfterParsing(): void
    {
        $input = new ArgvInput(['pulsar', 'cache:clear', '--force']);

        self::assertSame(['cache:clear', '--force'], $input->tokens);
    }

    #[Test]
    public function emptyArgvProducesEmptyState(): void
    {
        $input = new ArgvInput(['pulsar']);

        self::assertNull($input->commandName);
        self::assertSame([], $input->arguments);
        self::assertSame([], $input->options);
    }

    #[Test]
    public function dashAloneIsTreatedAsArgument(): void
    {
        $input = new ArgvInput(['pulsar', 'cmd', '-']);

        self::assertSame('cmd', $input->commandName);
        self::assertSame(['-'], $input->arguments);
    }

    #[Test]
    public function multipleShortFlagsAreSplit(): void
    {
        $input = new ArgvInput(['pulsar', 'test', '-abc']);

        self::assertTrue($input->hasOption('a'));
        self::assertTrue($input->hasOption('b'));
        self::assertTrue($input->hasOption('c'));
    }
}
