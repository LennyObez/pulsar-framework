<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\PackManifest;
use ReflectionClass;

#[CoversClass(PackManifest::class)]
final class PackManifestTest extends TestCase
{
    #[Test]
    public function it_creates_from_valid_array(): void
    {
        $manifest = PackManifest::fromArray([
            'name' => 'banking',
            'description' => 'Banking starter kit',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'compliancePresets' => ['PCI-DSS', 'PSD2'],
            'files' => ['src/Entity.php' => 'src/Entity/Entity.php'],
            'postInstallCommands' => ['composer dump-autoload'],
        ]);

        self::assertSame('banking', $manifest->name);
        self::assertSame('Banking starter kit', $manifest->description);
        self::assertSame('1.0.0', $manifest->version);
        self::assertSame('^1.0', $manifest->requiredPulsarVersion);
        self::assertSame(['PCI-DSS', 'PSD2'], $manifest->compliancePresets);
        self::assertSame(['src/Entity.php' => 'src/Entity/Entity.php'], $manifest->files);
        self::assertSame(['composer dump-autoload'], $manifest->postInstallCommands);
    }

    #[Test]
    public function it_defaults_optional_arrays(): void
    {
        $manifest = PackManifest::fromArray([
            'name' => 'test',
            'description' => 'Test pack',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
        ]);

        self::assertSame([], $manifest->compliancePresets);
        self::assertSame([], $manifest->files);
        self::assertSame([], $manifest->postInstallCommands);
    }

    #[Test]
    public function it_throws_on_missing_required_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('missing required fields');

        (void) PackManifest::fromArray([
            'name' => 'test',
            'description' => 'Test',
        ]);
    }

    #[Test]
    public function it_throws_on_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"name" must be a non-empty string');

        (void) PackManifest::fromArray([
            'name' => '',
            'description' => 'Test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
        ]);
    }

    #[Test]
    public function it_throws_on_invalid_compliance_presets_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"compliancePresets" must be an array');

        (void) PackManifest::fromArray([
            'name' => 'test',
            'description' => 'Test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'compliancePresets' => 'not-an-array',
        ]);
    }

    #[Test]
    public function it_throws_on_invalid_files_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"files" must be an array');

        (void) PackManifest::fromArray([
            'name' => 'test',
            'description' => 'Test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'files' => 'not-an-array',
        ]);
    }

    #[Test]
    public function it_throws_on_invalid_post_install_commands_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"postInstallCommands" must be an array');

        (void) PackManifest::fromArray([
            'name' => 'test',
            'description' => 'Test',
            'version' => '1.0.0',
            'requiredPulsarVersion' => '^1.0',
            'postInstallCommands' => 'not-an-array',
        ]);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $manifest = new PackManifest(
            name: 'test',
            description: 'Test pack',
            version: '1.0.0',
            requiredPulsarVersion: '^1.0',
            compliancePresets: [],
            files: [],
            postInstallCommands: [],
        );

        $reflection = new ReflectionClass($manifest);
        self::assertTrue($reflection->isReadOnly());
    }
}
