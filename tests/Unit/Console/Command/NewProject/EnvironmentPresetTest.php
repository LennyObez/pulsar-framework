<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;

#[CoversNothing]
final class EnvironmentPresetTest extends TestCase
{
    #[Test]
    public function fromInputReturnsLocalForNull(): void
    {
        self::assertSame(EnvironmentPreset::Local, EnvironmentPreset::fromInput(null));
    }

    #[Test]
    public function fromInputReturnsLocalForEmptyString(): void
    {
        self::assertSame(EnvironmentPreset::Local, EnvironmentPreset::fromInput(''));
    }

    #[Test]
    public function fromInputResolvesValidValues(): void
    {
        self::assertSame(EnvironmentPreset::Local, EnvironmentPreset::fromInput('local'));
        self::assertSame(EnvironmentPreset::Staging, EnvironmentPreset::fromInput('staging'));
        self::assertSame(EnvironmentPreset::Production, EnvironmentPreset::fromInput('production'));
    }

    #[Test]
    public function fromInputReturnsLocalForInvalidValue(): void
    {
        self::assertSame(EnvironmentPreset::Local, EnvironmentPreset::fromInput('invalid'));
    }

    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('local', EnvironmentPreset::Local->value);
        self::assertSame('staging', EnvironmentPreset::Staging->value);
        self::assertSame('production', EnvironmentPreset::Production->value);
    }
}
