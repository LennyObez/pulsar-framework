<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\DirectoryLayout;
use Pulsar\Console\Command\NewProject\ProjectPreset;

#[CoversClass(DirectoryLayout::class)]
final class DirectoryLayoutTest extends TestCase
{
    #[Test]
    public function minimalPresetReturnsBaseDirectoriesOnly(): void
    {
        $dirs = DirectoryLayout::forPreset(ProjectPreset::Minimal);

        self::assertContains('config', $dirs);
        self::assertContains('public', $dirs);
        self::assertContains('src', $dirs);
        self::assertContains('var/cache', $dirs);
        self::assertContains('var/log', $dirs);
        self::assertNotContains('resources/views', $dirs);
        self::assertNotContains('src/Http/Controller', $dirs);
    }

    #[Test]
    public function webPresetIncludesViewDirectories(): void
    {
        $dirs = DirectoryLayout::forPreset(ProjectPreset::Web);

        self::assertContains('resources/views', $dirs);
        self::assertContains('resources/assets', $dirs);
        self::assertContains('config', $dirs);
    }

    #[Test]
    public function apiPresetIncludesHttpDirectories(): void
    {
        $dirs = DirectoryLayout::forPreset(ProjectPreset::Api);

        self::assertContains('src/Http/Controller', $dirs);
        self::assertContains('src/Http/Middleware', $dirs);
        self::assertNotContains('resources/views', $dirs);
    }
}
