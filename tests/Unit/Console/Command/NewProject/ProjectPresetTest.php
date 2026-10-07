<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\ProjectPreset;

#[CoversNothing]
final class ProjectPresetTest extends TestCase
{
    #[Test]
    public function fromInputReturnsWebForNull(): void
    {
        self::assertSame(ProjectPreset::Web, ProjectPreset::fromInput(null));
    }

    #[Test]
    public function fromInputReturnsWebForEmptyString(): void
    {
        self::assertSame(ProjectPreset::Web, ProjectPreset::fromInput(''));
    }

    #[Test]
    public function fromInputResolvesValidValues(): void
    {
        self::assertSame(ProjectPreset::Minimal, ProjectPreset::fromInput('minimal'));
        self::assertSame(ProjectPreset::Web, ProjectPreset::fromInput('web'));
        self::assertSame(ProjectPreset::Api, ProjectPreset::fromInput('api'));
    }

    #[Test]
    public function fromInputReturnsWebForInvalidValue(): void
    {
        self::assertSame(ProjectPreset::Web, ProjectPreset::fromInput('graphql'));
    }

    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('minimal', ProjectPreset::Minimal->value);
        self::assertSame('web', ProjectPreset::Web->value);
        self::assertSame('api', ProjectPreset::Api->value);
    }
}
