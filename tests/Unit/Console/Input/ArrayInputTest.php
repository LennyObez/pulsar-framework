<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Input;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Input\ArrayInput;

#[CoversClass(ArrayInput::class)]
final class ArrayInputTest extends TestCase
{
    #[Test]
    public function constructWithCommandNameAndArguments(): void
    {
        $input = new ArrayInput('migrate:run', ['20240315_create_users']);

        self::assertSame('migrate:run', $input->commandName);
        self::assertSame('20240315_create_users', $input->getArgument(0));
    }

    #[Test]
    public function constructWithOptions(): void
    {
        $input = new ArrayInput('deploy', options: ['env' => 'staging', 'force' => true]);

        self::assertTrue($input->hasOption('env'));
        self::assertSame('staging', $input->getOption('env'));
        self::assertTrue($input->getOption('force'));
    }

    #[Test]
    public function getArgumentReturnsDefaultForMissingIndex(): void
    {
        $input = new ArrayInput('list');

        self::assertNull($input->getArgument(0));
        self::assertSame('default_val', $input->getArgument(5, 'default_val'));
    }

    #[Test]
    public function getArgumentWithStringKeyReturnsDefault(): void
    {
        $input = new ArrayInput('list', ['positional']);

        self::assertSame('fallback', $input->getArgument('named', 'fallback'));
    }

    #[Test]
    public function hasOptionReturnsFalseForMissing(): void
    {
        $input = new ArrayInput('list');

        self::assertFalse($input->hasOption('verbose'));
    }

    #[Test]
    public function getOptionReturnsDefaultForMissing(): void
    {
        $input = new ArrayInput('list');

        self::assertSame('table', $input->getOption('format', 'table'));
    }

    #[Test]
    public function tokensPropertyIncludesCommandName(): void
    {
        $input = new ArrayInput('cache:clear', ['all'], ['force' => true]);

        $tokens = $input->tokens;

        self::assertContains('cache:clear', $tokens);
        self::assertContains('all', $tokens);
        self::assertContains('--force', $tokens);
    }

    #[Test]
    public function tokensPropertyHandlesScalarOptionValues(): void
    {
        $input = new ArrayInput('build', options: ['target' => 'production']);

        $tokens = $input->tokens;

        self::assertContains('--target=production', $tokens);
    }

    #[Test]
    public function tokensPropertyOmitsNullCommandName(): void
    {
        $input = new ArrayInput(null, ['arg1']);

        $tokens = $input->tokens;

        self::assertSame(['arg1'], $tokens);
    }

    #[Test]
    public function tokensPropertySkipsNonScalarOptions(): void
    {
        $input = new ArrayInput('cmd', options: ['complex' => ['nested']]);

        $tokens = $input->tokens;

        self::assertSame(['cmd'], $tokens);
    }
}
