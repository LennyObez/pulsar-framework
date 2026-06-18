<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ConfigReferenceRenderer;
use Pulsar\Introspection\Data\ConfigPropertySchema;
use Pulsar\Introspection\Data\ConfigSchemaEntry;

#[CoversClass(ConfigReferenceRenderer::class)]
final class ConfigReferenceRendererTest extends TestCase
{
    #[Test]
    public function rendersOneSectionPerConfigClassWithTypedDefaults(): void
    {
        $markdown = new ConfigReferenceRenderer()->render([
            new ConfigSchemaEntry('App\\Config\\MailConfig', [
                new ConfigPropertySchema('host', 'string', 'localhost'),
                new ConfigPropertySchema('port', 'int', 25),
                new ConfigPropertySchema('tls', 'bool', true),
                new ConfigPropertySchema('timeout', '?int', null),
            ]),
        ]);

        self::assertStringContainsString('# Configuration reference', $markdown);
        self::assertStringContainsString('## App\\Config\\MailConfig', $markdown);
        self::assertStringContainsString('| Option | Type | Default |', $markdown);
        self::assertStringContainsString('| `host` | `string` | `localhost` |', $markdown);
        self::assertStringContainsString('| `port` | `int` | `25` |', $markdown);
        self::assertStringContainsString('| `tls` | `bool` | `true` |', $markdown);
        self::assertStringContainsString('| `timeout` | `?int` | `null` |', $markdown);
    }

    #[Test]
    public function escapesPipesInDefaultsToProtectTheTable(): void
    {
        $markdown = new ConfigReferenceRenderer()->render([
            new ConfigSchemaEntry('X', [new ConfigPropertySchema('pattern', 'string', 'a|b')]),
        ]);

        self::assertStringContainsString('`a\\|b`', $markdown);
    }

    #[Test]
    public function summarizesArrayDefaults(): void
    {
        $markdown = new ConfigReferenceRenderer()->render([
            new ConfigSchemaEntry('X', [new ConfigPropertySchema('hosts', 'array', ['a', 'b', 'c'])]),
        ]);

        self::assertStringContainsString('`array(3)`', $markdown);
    }

    #[Test]
    public function emptyScalarHeadingOnlyWhenNoEntries(): void
    {
        self::assertSame("# Configuration reference\n", new ConfigReferenceRenderer()->render([]));
    }
}
