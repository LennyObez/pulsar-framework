<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Cms\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use Pulsar\Extension\Cms\AI\PromptTemplate;
use Pulsar\Extension\Cms\AI\PromptTemplateRegistry;

#[CoversClass(ContentAssistant::class)]
final class ContentAssistantTest extends TestCase
{
    #[Test]
    public function generateDraftDelegatesCorrectPromptToClient(): void
    {
        $expectedResponse = new AiResponse(
            content: 'Generated article about PHP frameworks.',
            inputTokens: 50,
            outputTokens: 100,
            finishReason: 'stop',
        );

        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::stringContains('PHP frameworks'),
                self::callback(static fn(AiRequestOptions $opts): bool => $opts->temperature === 0.7
                    && $opts->maxTokens === 1000
                    && $opts->systemPrompt !== null),
            )
            ->willReturn($expectedResponse);

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateDraft('PHP frameworks', 'professional', 500);

        self::assertSame('Generated article about PHP frameworks.', $result->content);
        self::assertSame('stop', $result->finishReason);
        self::assertSame(50, $result->inputTokens);
        self::assertSame(100, $result->outputTokens);
    }

    #[Test]
    public function generateDraftUsesTemplateWhenAvailable(): void
    {
        $template = new PromptTemplate(
            name: 'generate_draft',
            template: 'Write about {topic} in {tone} style, {targetWords} words.',
            systemPrompt: 'Expert writer system prompt.',
            defaultTemperature: 0.8,
            defaultMaxTokens: 2000,
        );

        $registry = new PromptTemplateRegistry();
        $registry->register($template);

        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                'Write about AI in casual style, 300 words.',
                self::callback(static fn(AiRequestOptions $opts): bool => $opts->temperature === 0.8
                    && $opts->maxTokens === 600
                    && $opts->systemPrompt === 'Expert writer system prompt.'),
            )
            ->willReturn(new AiResponse('AI content', 10, 20, 'stop'));

        $assistant = new ContentAssistant($client, $registry);
        $result = $assistant->generateDraft('AI', 'casual', 300);

        self::assertSame('AI content', $result->content);
    }

    #[Test]
    public function summarizePassesCorrectParameters(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::stringContains('Summarize'),
                self::callback(static fn(AiRequestOptions $opts): bool => $opts->temperature === 0.3
                    && $opts->maxTokens === 256),
            )
            ->willReturn(new AiResponse('Summary text.', 100, 30, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->summarize('Long article content here...', 2);

        self::assertSame('Summary text.', $result->content);
    }

    #[Test]
    public function suggestTitleReturnsMultipleTitles(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse("Title One\nTitle Two\nTitle Three", 50, 30, 'stop'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->suggestTitle('Article content', 3);

        self::assertStringContainsString('Title One', $result->content);
        self::assertSame('stop', $result->finishReason);
    }

    #[Test]
    public function suggestMetaDescriptionRespectsMaxLength(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::stringContains('at most 120 characters'),
                self::anything(),
            )
            ->willReturn(new AiResponse('Short meta description.', 20, 10, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->suggestMetaDescription('Content', 120);

        self::assertSame('Short meta description.', $result->content);
    }

    #[Test]
    public function translateContentIncludesLocales(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::logicalAnd(
                    self::stringContains('en-US'),
                    self::stringContains('fr-FR'),
                ),
                self::anything(),
            )
            ->willReturn(new AiResponse('Texte traduit.', 30, 25, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->translateContent('Original text.', 'en-US', 'fr-FR');

        self::assertSame('Texte traduit.', $result->content);
    }

    #[Test]
    public function improveReadabilityDelegatesContent(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse('Improved text.', 40, 35, 'stop'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->improveReadability('Complex original text.');

        self::assertSame('Improved text.', $result->content);
    }

    #[Test]
    public function generateOutlineIncludesKeywordsAndAudience(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::logicalAnd(
                    self::stringContains('php, performance'),
                    self::stringContains('developers'),
                    self::stringContains('Optimization'),
                ),
                self::anything(),
            )
            ->willReturn(new AiResponse('## H2 Heading', 30, 50, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateOutline('Optimization', ['php', 'performance'], 'developers');

        self::assertStringContainsString('H2', $result->content);
    }

    #[Test]
    public function expandContentUsesCorrectTokenLimit(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::stringContains('approximately 1000 words'),
                self::callback(static fn(AiRequestOptions $opts): bool => $opts->maxTokens === 2000),
            )
            ->willReturn(new AiResponse('Expanded content.', 50, 200, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->expandContent('Short text.', 1000);

        self::assertSame('Expanded content.', $result->content);
    }

    #[Test]
    public function condenseContentPreservesKeyPoints(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::stringContains('approximately 150 words'),
                self::callback(static fn(AiRequestOptions $opts): bool => $opts->temperature === 0.4),
            )
            ->willReturn(new AiResponse('Condensed text.', 100, 30, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->condenseContent('Very long text...', 150);

        self::assertSame('Condensed text.', $result->content);
    }

    #[Test]
    public function adjustToneUsesTargetTone(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::stringContains('humorous tone'),
                self::anything(),
            )
            ->willReturn(new AiResponse('Funny version.', 40, 40, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->adjustTone('Serious content.', 'humorous');

        self::assertSame('Funny version.', $result->content);
    }

    #[Test]
    public function generateFaqReturnsQAPairs(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse("Q: What?\nA: This.\nQ: Why?\nA: Because.", 50, 40, 'stop'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateFaq('Content about topic.', 2);

        self::assertStringContainsString('Q:', $result->content);
        self::assertStringContainsString('A:', $result->content);
    }

    #[Test]
    public function generateProductDescriptionIncludesFeatures(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::logicalAnd(
                    self::stringContains('Widget Pro'),
                    self::stringContains('fast, durable'),
                ),
                self::anything(),
            )
            ->willReturn(new AiResponse('Product description.', 30, 60, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateProductDescription('Widget Pro', ['fast', 'durable'], 'casual');

        self::assertSame('Product description.', $result->content);
    }

    #[Test]
    public function extractKeywordsReturnsKeywordList(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse("php\nperformance\noptimization", 30, 10, 'stop'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->extractKeywords('Article about PHP performance.', 3);

        self::assertStringContainsString('php', $result->content);
    }

    #[Test]
    public function analyzeSeoScoreRequestsJsonOutput(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::logicalAnd(
                    self::stringContains('JSON object'),
                    self::stringContains('caching'),
                ),
                self::callback(static fn(AiRequestOptions $opts): bool => $opts->temperature === 0.2),
            )
            ->willReturn(new AiResponse('{"score": 85}', 50, 30, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->analyzeSeoScore('Article about caching.', 'caching');

        self::assertStringContainsString('85', $result->content);
    }

    #[Test]
    public function suggestSlugReturnsUrlFriendlySlug(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse('getting-started-with-php', 10, 5, 'stop'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->suggestSlug('Getting Started with PHP');

        self::assertSame('getting-started-with-php', $result->content);
    }

    #[Test]
    public function generateAltTextIncludesContext(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())
            ->method('complete')
            ->with(
                self::logicalAnd(
                    self::stringContains('sunset over mountains'),
                    self::stringContains('travel blog'),
                ),
                self::anything(),
            )
            ->willReturn(new AiResponse('Sunset over mountain range', 20, 10, 'stop'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateAltText('sunset over mountains', 'travel blog post');

        self::assertSame('Sunset over mountain range', $result->content);
    }

    #[Test]
    public function optimizeHeadingsAnalyzesStructure(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse('## Suggested heading improvements', 30, 40, 'stop'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->optimizeHeadings('<h1>Main</h1><h3>Skipped H2</h3>');

        self::assertStringContainsString('heading', $result->content);
    }

    #[Test]
    public function errorResponsePropagatesFromClient(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(AiResponse::error('Rate limit exceeded'));

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateDraft('Test topic');

        self::assertTrue($result->isError());
        self::assertStringContainsString('Rate limit', $result->content);
    }

    #[Test]
    public function returnsAiResponseType(): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse('Content', 10, 20, 'stop', [], 'gpt-4o'),
        );

        $assistant = new ContentAssistant($client);
        $result = $assistant->generateDraft('Topic');

        self::assertInstanceOf(AiResponse::class, $result);
        self::assertSame('gpt-4o', $result->model);
        self::assertSame(30, $result->totalTokens());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodProvider(): iterable
    {
        yield 'generateDraft' => ['generateDraft'];
        yield 'summarize' => ['summarize'];
        yield 'suggestTitle' => ['suggestTitle'];
        yield 'suggestMetaDescription' => ['suggestMetaDescription'];
        yield 'improveReadability' => ['improveReadability'];
        yield 'expandContent' => ['expandContent'];
        yield 'condenseContent' => ['condenseContent'];
        yield 'extractKeywords' => ['extractKeywords'];
        yield 'suggestSlug' => ['suggestSlug'];
        yield 'optimizeHeadings' => ['optimizeHeadings'];
    }

    #[Test]
    #[DataProvider('methodProvider')]
    public function allMethodsReturnAiResponseInstance(string $method): void
    {
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse('Test output', 5, 10, 'stop'),
        );

        $assistant = new ContentAssistant($client);

        $result = match ($method) {
            'generateDraft' => $assistant->generateDraft('topic'),
            'summarize' => $assistant->summarize('text'),
            'suggestTitle' => $assistant->suggestTitle('text'),
            'suggestMetaDescription' => $assistant->suggestMetaDescription('text'),
            'improveReadability' => $assistant->improveReadability('text'),
            'expandContent' => $assistant->expandContent('text'),
            'condenseContent' => $assistant->condenseContent('text'),
            'extractKeywords' => $assistant->extractKeywords('text'),
            'suggestSlug' => $assistant->suggestSlug('title'),
            'optimizeHeadings' => $assistant->optimizeHeadings('text'),
            default => self::fail("Unhandled method: $method"),
        };

        self::assertInstanceOf(AiResponse::class, $result);
    }
}
