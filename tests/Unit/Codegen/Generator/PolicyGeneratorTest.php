<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Generator\PolicyGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Template\TemplateRenderer;

#[CoversClass(PolicyGenerator::class)]
final class PolicyGeneratorTest extends TestCase
{
    private PolicyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new PolicyGenerator(
            new TemplateRenderer(),
            new PathValidator('/project'),
        );
    }

    #[Test]
    public function generateProducesOneFile(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);

        self::assertCount(1, $result->files());
    }

    #[Test]
    public function generateProducesPolicyClass(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $file = $result->files()[0];

        self::assertStringEndsWith('UserPolicy.php', $file->targetPath);
        self::assertStringContainsString('class UserPolicy', $file->content);
    }

    #[Test]
    public function generateIsDenyByDefault(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        // All methods return false (deny-by-default)
        self::assertStringContainsString('DENY-BY-DEFAULT', $content);
        self::assertStringContainsString('function viewAny(): bool', $content);
        self::assertStringContainsString('function view(): bool', $content);
        self::assertStringContainsString('function create(): bool', $content);
        self::assertStringContainsString('function update(): bool', $content);
        self::assertStringContainsString('function delete(): bool', $content);

        // Count 'return false;' occurrences — should be 5 (one per method)
        self::assertSame(5, substr_count($content, 'return false;'));
    }

    #[Test]
    public function generateUsesEntityNameInComments(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result = $this->generator->generate($entity, $config);
        $content = $result->files()[0]->content;

        self::assertStringContainsString('user', $content);
    }

    #[Test]
    public function generateIsDeterministic(): void
    {
        $entity = $this->createEntity();
        $config = new GeneratorConfig('/project/src');

        $result1 = $this->generator->generate($entity, $config);
        $result2 = $this->generator->generate($entity, $config);

        self::assertSame($result1->files()[0]->content, $result2->files()[0]->content);
    }

    private function createEntity(): EntityDefinition
    {
        return new EntityDefinition(
            className: 'User',
            namespace: 'App\\Entity',
            tableName: 'users',
            properties: [],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }
}
