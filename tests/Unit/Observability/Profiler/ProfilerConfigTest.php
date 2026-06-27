<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Profiler\ProfilerConfig;

#[CoversClass(ProfilerConfig::class)]
final class ProfilerConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new ProfilerConfig();

        self::assertFalse($config->enabled);
        self::assertSame(512, $config->maxEntries);
        self::assertSame(50, $config->maxProfiles);
    }

    #[Test]
    public function fromArrayParsesEveryField(): void
    {
        $config = ProfilerConfig::fromArray([
            'enabled' => true,
            'max_entries' => '1000',
            'max_profiles' => '10',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(1000, $config->maxEntries);
        self::assertSame(10, $config->maxProfiles);
    }
}
