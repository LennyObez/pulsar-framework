<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\ComposerJsonGenerator;
use Pulsar\Console\Command\NewProject\ProjectPreset;

use function assert;
use function is_array;
use function is_string;

#[CoversClass(ComposerJsonGenerator::class)]
final class ComposerJsonGeneratorTest extends TestCase
{
    #[Test]
    public function generateProducesValidJson(): void
    {
        $generator = new ComposerJsonGenerator();

        $json = $generator->generate('MyHealthApp', ProjectPreset::Web);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        self::assertArrayHasKey('name', $decoded);
        self::assertArrayHasKey('require', $decoded);
        self::assertSame('app/my-health-app', $decoded['name']);
        $require = $decoded['require'];
        assert(is_array($require));
        self::assertSame('>=8.5', $require['php']);
        self::assertSame('^1.0', $require['pulsar/framework']);
    }

    #[Test]
    public function generateIncludesPresetInDescription(): void
    {
        $generator = new ComposerJsonGenerator();

        $json = $generator->generate('BankingApi', ProjectPreset::Api);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        $description = $decoded['description'];
        assert(is_string($description));
        self::assertStringContainsString('api', $description);
    }

    #[Test]
    public function generateSlugifiesAppName(): void
    {
        $generator = new ComposerJsonGenerator();

        $json = $generator->generate('My Complex_App Name', ProjectPreset::Minimal);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        self::assertSame('app/my-complex-app-name', $decoded['name']);
    }

    #[Test]
    public function generateSetsAutoloadPaths(): void
    {
        $generator = new ComposerJsonGenerator();

        $json = $generator->generate('TestApp', ProjectPreset::Web);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        $autoload = $decoded['autoload'];
        assert(is_array($autoload));
        $autoloadDev = $decoded['autoload-dev'];
        assert(is_array($autoloadDev));

        self::assertSame(['App\\' => 'src/'], $autoload['psr-4']);
        self::assertSame(['Tests\\' => 'tests/'], $autoloadDev['psr-4']);
    }

    #[Test]
    public function generateEndsWithNewline(): void
    {
        $generator = new ComposerJsonGenerator();

        $json = $generator->generate('App', ProjectPreset::Minimal);

        self::assertStringEndsWith("\n", $json);
    }
}
