<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\Lowercase;
use Pulsar\Http\Validation\Filter\SanitizationPipeline;
use Pulsar\Http\Validation\Filter\SanitizationResult;
use Pulsar\Http\Validation\Filter\StripTags;
use Pulsar\Http\Validation\Filter\Trim;

#[CoversClass(SanitizationPipeline::class)]
#[CoversClass(SanitizationResult::class)]
final class SanitizationPipelineTest extends TestCase
{
    #[Test]
    public function appliesFiltersInOrder(): void
    {
        $pipeline = new SanitizationPipeline(new Trim(), new Lowercase());
        $result = $pipeline->sanitize(['name' => '  HELLO  ']);

        self::assertSame(['name' => 'hello'], $result->values());
    }

    #[Test]
    public function preservesOriginalValues(): void
    {
        $pipeline = new SanitizationPipeline(new Trim());
        $result = $pipeline->sanitize(['name' => '  hello  ']);

        self::assertSame('  hello  ', $result->original('name'));
        self::assertSame('hello', $result->values()['name']);
    }

    #[Test]
    public function detectsModification(): void
    {
        $pipeline = new SanitizationPipeline(new Trim());

        $modified = $pipeline->sanitize(['name' => '  hello  ']);
        self::assertTrue($modified->wasModified());

        $unmodified = $pipeline->sanitize(['name' => 'hello']);
        self::assertFalse($unmodified->wasModified());
    }

    #[Test]
    public function detectsFieldModification(): void
    {
        $pipeline = new SanitizationPipeline(new Trim());
        $result = $pipeline->sanitize([
            'name' => '  hello  ',
            'email' => 'test@example.com',
        ]);

        self::assertTrue($result->fieldWasModified('name'));
        self::assertFalse($result->fieldWasModified('email'));
        self::assertFalse($result->fieldWasModified('nonexistent'));
    }

    #[Test]
    public function chainsMultipleFilters(): void
    {
        $pipeline = new SanitizationPipeline(new StripTags(), new Trim(), new Lowercase());
        $result = $pipeline->sanitize(['bio' => '  <b>HELLO</b>  ']);

        self::assertSame('hello', $result->values()['bio']);
    }

    #[Test]
    public function emptyPipelineReturnsDataUnchanged(): void
    {
        $pipeline = new SanitizationPipeline();
        $data = ['name' => 'hello'];
        $result = $pipeline->sanitize($data);

        self::assertSame($data, $result->values());
        self::assertFalse($result->wasModified());
    }

    #[Test]
    public function originalReturnsNullForMissingField(): void
    {
        $pipeline = new SanitizationPipeline(new Trim());
        $result = $pipeline->sanitize(['name' => 'hello']);

        self::assertNull($result->original('nonexistent'));
    }
}
