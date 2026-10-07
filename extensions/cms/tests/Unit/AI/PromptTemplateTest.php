<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\PromptTemplate;

#[CoversClass(PromptTemplate::class)]
final class PromptTemplateTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $template = new PromptTemplate(
            name: 'summarize',
            template: 'Summarize: {text}',
            systemPrompt: 'You are a summarizer.',
            defaultTemperature: 0.5,
            defaultMaxTokens: 512,
        );

        self::assertSame('summarize', $template->name);
        self::assertSame('Summarize: {text}', $template->template);
        self::assertSame('You are a summarizer.', $template->systemPrompt);
        self::assertSame(0.5, $template->defaultTemperature);
        self::assertSame(512, $template->defaultMaxTokens);
    }

    #[Test]
    public function renderReplacesPlaceholders(): void
    {
        $template = new PromptTemplate(
            name: 'translate',
            template: 'Translate from {source} to {target}: {text}',
            systemPrompt: 'Translator',
            defaultTemperature: 0.3,
            defaultMaxTokens: 256,
        );

        $result = $template->render([
            'source' => 'English',
            'target' => 'French',
            'text' => 'Hello world',
        ]);

        self::assertSame('Translate from English to French: Hello world', $result);
    }

    #[Test]
    public function renderLeavesUnknownPlaceholders(): void
    {
        $template = new PromptTemplate(
            name: 'test',
            template: 'Hello {name}, your code is {unknown}',
            systemPrompt: '',
            defaultTemperature: 0.7,
            defaultMaxTokens: 1024,
        );

        $result = $template->render(['name' => 'Alice']);

        self::assertSame('Hello Alice, your code is {unknown}', $result);
    }

    #[Test]
    public function renderAcceptsNumericValues(): void
    {
        $template = new PromptTemplate(
            name: 'test',
            template: 'Generate {count} words at {temp} temperature',
            systemPrompt: '',
            defaultTemperature: 0.7,
            defaultMaxTokens: 100,
        );

        $result = $template->render(['count' => 50, 'temp' => 0.5]);

        self::assertSame('Generate 50 words at 0.5 temperature', $result);
    }

    #[Test]
    public function renderWithEmptyVariablesReturnsTemplate(): void
    {
        $template = new PromptTemplate(
            name: 'test',
            template: 'No placeholders here',
            systemPrompt: '',
            defaultTemperature: 0.7,
            defaultMaxTokens: 100,
        );

        self::assertSame('No placeholders here', $template->render([]));
    }
}
