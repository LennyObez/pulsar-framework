<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\ProjectMetadataBuilder;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use stdClass;

#[CoversClass(ProjectMetadataBuilder::class)]
final class ProjectMetadataBuilderTest extends TestCase
{
    private SensitiveDataScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new SensitiveDataScrubber();
    }

    #[Test]
    public function addSectionStoresData(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('info', ['key' => 'value']);

        $result = $builder->build($this->scrubber);

        self::assertSame('test', $result->contributorId);
        self::assertArrayHasKey('info', $result->sections);
        self::assertSame(['key' => 'value'], $result->sections['info']);
    }

    #[Test]
    public function addSectionNormalizesKey(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('My Section KEY', ['a' => 1]);

        $result = $builder->build($this->scrubber);

        self::assertArrayHasKey('mysectionkey', $result->sections);
    }

    #[Test]
    public function addSectionRejectsEmptyNormalizedKey(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('!!!', ['a' => 1]);

        $warnings = $builder->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('normalized to empty string', $warnings[0]);

        $result = $builder->build($this->scrubber);
        self::assertSame([], $result->sections);
    }

    #[Test]
    public function addSectionRejectsWhenMaxSectionsReached(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');

        for ($i = 0; $i < 64; $i++) {
            $builder->addSection("section{$i}", ['i' => $i]);
        }

        self::assertSame([], $builder->warnings());

        $builder->addSection('overflow', ['x' => 1]);

        $warnings = $builder->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('maximum section count', $warnings[0]);
    }

    #[Test]
    public function addSectionRejectsDeepNesting(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');

        // Build a structure nested 9 levels deep (exceeds max of 8)
        $data = ['level' => 'leaf'];
        for ($i = 0; $i < 8; $i++) {
            $data = ['nested' => $data];
        }

        $builder->addSection('deep', $data);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Maximum nesting depth', $warnings[0]);
    }

    #[Test]
    public function addSectionRejectsTooManyKeys(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');

        $data = [];
        for ($i = 0; $i < 210; $i++) {
            $data["key{$i}"] = $i;
        }

        $builder->addSection('wide', $data);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('exceeding limit of 200', $warnings[0]);

        $result = $builder->build($this->scrubber);
        self::assertCount(200, $result->sections['wide']);
    }

    #[Test]
    public function addSectionRejectsByteLimitExceeded(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');

        // Add a section that fills most of the 256KB (262,144 bytes) budget
        $builder->addSection('big', ['payload' => str_repeat('x', 260_000)]);
        self::assertSame([], $builder->warnings());

        // This should exceed the byte limit
        $builder->addSection('overflow', ['payload' => str_repeat('y', 5_000)]);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('byte limit', $warnings[0]);
    }

    #[Test]
    public function addSectionRejectsObjects(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('objects', ['obj' => new stdClass()]);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('objects are not allowed', $warnings[0]);

        $result = $builder->build($this->scrubber);
        self::assertArrayNotHasKey('obj', $result->sections['objects']);
    }

    #[Test]
    public function addSectionRejectsClosures(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('closures', ['fn' => static fn(): bool => true]);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('closures are not allowed', $warnings[0]);

        $result = $builder->build($this->scrubber);
        self::assertArrayNotHasKey('fn', $result->sections['closures']);
    }

    #[Test]
    public function addSectionRejectsResources(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('resources', ['res' => $resource]);

        fclose($resource);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('resources are not allowed', $warnings[0]);

        $result = $builder->build($this->scrubber);
        self::assertArrayNotHasKey('res', $result->sections['resources']);
    }

    #[Test]
    public function addSectionDuplicateKeyOverwritesWithWarning(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('data', ['version' => 1]);
        $builder->addSection('data', ['version' => 2]);

        $warnings = $builder->warnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('already exists, overwriting', $warnings[0]);

        $result = $builder->build($this->scrubber);
        self::assertSame(['version' => 2], $result->sections['data']);
    }

    #[Test]
    public function buildScrubsSensitiveData(): void
    {
        $builder = ProjectMetadataBuilder::forContributor('test');
        $builder->addSection('config', [
            'host' => 'localhost',
            'password' => 'secret123',
            'api_key' => 'key-abc',
        ]);

        $result = $builder->build($this->scrubber);

        self::assertSame('localhost', $result->sections['config']['host']);
        self::assertSame('[REDACTED]', $result->sections['config']['password']);
        self::assertSame('[REDACTED]', $result->sections['config']['api_key']);
    }
}
