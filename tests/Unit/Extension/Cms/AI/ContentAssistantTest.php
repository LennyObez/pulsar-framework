<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use RuntimeException;

#[CoversClass(ContentAssistant::class)]
final class ContentAssistantTest extends TestCase
{
    #[Test]
    public function generate_draft_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;

        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->generateDraft('PHP 8.5 features', 'casual', 300);

        self::assertStringContainsString('300-word article', $capturedPrompt);
        self::assertStringContainsString('PHP 8.5 features', $capturedPrompt);
        self::assertStringContainsString('casual', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.7, $capturedOptions->temperature);
        self::assertNotNull($capturedOptions->systemPrompt);
    }

    #[Test]
    public function summarize_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;

        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->summarize('Some long content here', 5);

        self::assertStringContainsString('5 sentences', $capturedPrompt);
        self::assertStringContainsString('Some long content here', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.3, $capturedOptions->temperature);
    }

    #[Test]
    public function suggest_title_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;

        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->suggestTitle('Article about testing', 3);

        self::assertStringContainsString('3 titles', $capturedPrompt);
        self::assertStringContainsString('one per line', $capturedPrompt);
        self::assertStringContainsString('Article about testing', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.8, $capturedOptions->temperature);
    }

    #[Test]
    public function suggest_meta_description_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;

        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->suggestMetaDescription('Article content', 120);

        self::assertStringContainsString('120 characters', $capturedPrompt);
        self::assertStringContainsString('meta description', $capturedPrompt);
        self::assertStringContainsString('Article content', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.5, $capturedOptions->temperature);
    }

    #[Test]
    public function translate_content_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;

        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->translateContent('Hello world', 'en', 'fr');

        self::assertStringContainsString('en', $capturedPrompt);
        self::assertStringContainsString('fr', $capturedPrompt);
        self::assertStringContainsString('Hello world', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.3, $capturedOptions->temperature);
    }

    #[Test]
    public function improve_readability_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;

        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->improveReadability('Complex text here');

        self::assertStringContainsString('readability', $capturedPrompt);
        self::assertStringContainsString('Complex text here', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.5, $capturedOptions->temperature);
    }

    #[Test]
    public function generate_outline_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->generateOutline('PHP patterns', ['design patterns', 'SOLID'], 'developers');

        self::assertStringContainsString('PHP patterns', $capturedPrompt);
        self::assertStringContainsString('design patterns, SOLID', $capturedPrompt);
        self::assertStringContainsString('developers', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.7, $capturedOptions->temperature);
        self::assertSame(1024, $capturedOptions->maxTokens);
    }

    #[Test]
    public function expand_content_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->expandContent('Short text', 1000);

        self::assertStringContainsString('1000 words', $capturedPrompt);
        self::assertStringContainsString('Short text', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.6, $capturedOptions->temperature);
        self::assertSame(2000, $capturedOptions->maxTokens);
    }

    #[Test]
    public function condense_content_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->condenseContent('Long verbose text', 100);

        self::assertStringContainsString('100 words', $capturedPrompt);
        self::assertStringContainsString('Long verbose text', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.4, $capturedOptions->temperature);
        self::assertSame(200, $capturedOptions->maxTokens);
    }

    #[Test]
    public function adjust_tone_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->adjustTone('Some text', 'humorous');

        self::assertStringContainsString('humorous', $capturedPrompt);
        self::assertStringContainsString('Some text', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.6, $capturedOptions->temperature);
    }

    #[Test]
    public function generate_faq_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->generateFaq('Article content', 3);

        self::assertStringContainsString('3', $capturedPrompt);
        self::assertStringContainsString('Article content', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.6, $capturedOptions->temperature);
        self::assertSame(1024, $capturedOptions->maxTokens);
    }

    #[Test]
    public function generate_product_description_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->generateProductDescription('Widget Pro', ['fast', 'durable'], 'casual');

        self::assertStringContainsString('Widget Pro', $capturedPrompt);
        self::assertStringContainsString('fast, durable', $capturedPrompt);
        self::assertStringContainsString('casual', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.7, $capturedOptions->temperature);
        self::assertSame(512, $capturedOptions->maxTokens);
    }

    #[Test]
    public function extract_keywords_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->extractKeywords('SEO article content', 5);

        self::assertStringContainsString('5', $capturedPrompt);
        self::assertStringContainsString('SEO article content', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.3, $capturedOptions->temperature);
        self::assertSame(256, $capturedOptions->maxTokens);
    }

    #[Test]
    public function analyze_seo_score_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->analyzeSeoScore('Content about PHP', 'PHP');

        self::assertStringContainsString('PHP', $capturedPrompt);
        self::assertStringContainsString('Content about PHP', $capturedPrompt);
        self::assertStringContainsString('JSON', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.2, $capturedOptions->temperature);
        self::assertSame(1024, $capturedOptions->maxTokens);
    }

    #[Test]
    public function suggest_slug_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->suggestSlug('My Great Article Title');

        self::assertStringContainsString('My Great Article Title', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.3, $capturedOptions->temperature);
        self::assertSame(64, $capturedOptions->maxTokens);
    }

    #[Test]
    public function generate_alt_text_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->generateAltText('A photo of a cat', 'Article about pets');

        self::assertStringContainsString('A photo of a cat', $capturedPrompt);
        self::assertStringContainsString('Article about pets', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.4, $capturedOptions->temperature);
        self::assertSame(128, $capturedOptions->maxTokens);
    }

    #[Test]
    public function optimize_headings_constructs_correct_prompt(): void
    {
        $capturedPrompt = '';
        $capturedOptions = null;
        $provider = $this->createCapturingProvider($capturedPrompt, $capturedOptions);
        $assistant = new ContentAssistant($provider);

        $assistant->optimizeHeadings('Content with headings');

        self::assertStringContainsString('Content with headings', $capturedPrompt);
        self::assertStringContainsString('heading', $capturedPrompt);
        self::assertNotNull($capturedOptions);
        self::assertSame(0.4, $capturedOptions->temperature);
        self::assertSame(512, $capturedOptions->maxTokens);
    }

    #[Test]
    public function returns_provider_response_unchanged(): void
    {
        $expected = new AiResponse('Generated content', 100, 200, 'stop');

        $provider = new class ($expected) implements AiClientInterface {
            public function __construct(private readonly AiResponse $response) {}

            public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
            {
                return $this->response;
            }

            public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
            {
                return $this->response;
            }

            public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): \Pulsar\AI\Embedding\EmbeddingResult
            {
                throw new RuntimeException('Not implemented');
            }

            public function structuredOutput(string $prompt, array $schema, AiRequestOptions $options = new AiRequestOptions()): AiResponse
            {
                return $this->response;
            }

            public function providerName(): string
            {
                return 'test';
            }
        };

        $assistant = new ContentAssistant($provider);
        $result = $assistant->generateDraft('test');

        self::assertSame('Generated content', $result->content);
        self::assertSame(100, $result->inputTokens);
        self::assertSame(200, $result->outputTokens);
        self::assertSame('stop', $result->finishReason);
    }

    /**
     * @param-out string $capturedPrompt
     * @param-out AiRequestOptions|null $capturedOptions
     */
    private function createCapturingProvider(string &$capturedPrompt, ?AiRequestOptions &$capturedOptions): AiClientInterface
    {
        return new class ($capturedPrompt, $capturedOptions) implements AiClientInterface {
            public function __construct(
                public string &$capturedPrompt,
                public ?AiRequestOptions &$capturedOptions,
            ) {}

            public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
            {
                return new AiResponse('test response', 10, 20, 'stop');
            }

            public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
            {
                $this->capturedPrompt = $prompt;
                $this->capturedOptions = $options;

                return new AiResponse('test response', 10, 20, 'stop');
            }

            public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): \Pulsar\AI\Embedding\EmbeddingResult
            {
                throw new RuntimeException('Not implemented');
            }

            public function structuredOutput(string $prompt, array $schema, AiRequestOptions $options = new AiRequestOptions()): AiResponse
            {
                return new AiResponse('test response', 10, 20, 'stop');
            }

            public function providerName(): string
            {
                return 'test';
            }
        };
    }
}
