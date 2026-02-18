<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\PromptTemplate;

#[CoversClass(PromptTemplate::class)]
final class PromptTemplateTest extends TestCase
{
    #[Test]
    public function renderReplacesPlaceholders(): void
    {
        $template = new PromptTemplate(
            name: 'test',
            template: 'Write a {count}-word article about {topic}.',
            systemPrompt: 'You are a writer.',
            defaultTemperature: 0.7,
            defaultMaxTokens: 1024,
        );

        $result = $template->render(['count' => 500, 'topic' => 'PHP']);

        self::assertSame('Write a 500-word article about PHP.', $result);
    }

    #[Test]
    public function renderLeavesUnknownPlaceholdersIntact(): void
    {
        $template = new PromptTemplate(
            name: 'test',
            template: 'Hello {name}, welcome to {place}.',
            systemPrompt: 'System',
            defaultTemperature: 0.5,
            defaultMaxTokens: 512,
        );

        $result = $template->render(['name' => 'Alice']);

        self::assertSame('Hello Alice, welcome to {place}.', $result);
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $template = new PromptTemplate(
            name: 'generate_draft',
            template: 'Template text',
            systemPrompt: 'System prompt',
            defaultTemperature: 0.8,
            defaultMaxTokens: 2048,
        );

        self::assertSame('generate_draft', $template->name);
        self::assertSame('Template text', $template->template);
        self::assertSame('System prompt', $template->systemPrompt);
        self::assertSame(0.8, $template->defaultTemperature);
        self::assertSame(2048, $template->defaultMaxTokens);
    }

    #[Test]
    public function renderWithNoVariablesReturnsTemplate(): void
    {
        $template = new PromptTemplate(
            name: 'test',
            template: 'Static prompt with no placeholders.',
            systemPrompt: 'System',
            defaultTemperature: 0.5,
            defaultMaxTokens: 256,
        );

        $result = $template->render([]);

        self::assertSame('Static prompt with no placeholders.', $result);
    }
}
