<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use Pulsar\Extension\Cms\AI\LlmOptions;
use Pulsar\Extension\Cms\AI\LlmProviderInterface;
use Pulsar\Extension\Cms\AI\LlmResponse;
use Pulsar\Extension\Cms\AI\PromptTemplate;
use Pulsar\Extension\Cms\AI\PromptTemplateRegistry;

/**
 * Tests the template-based code paths in ContentAssistant.
 * The existing ContentAssistantTest covers the fallback (no-template) paths.
 */
#[CoversClass(ContentAssistant::class)]
final class ContentAssistantTemplateTest extends TestCase
{
    public string $capturedPrompt;
    public ?LlmOptions $capturedOptions;
    private PromptTemplateRegistry $registry;
    private LlmProviderInterface $provider;

    protected function setUp(): void
    {
        $this->capturedPrompt = '';
        $this->capturedOptions = null;
        $this->registry = new PromptTemplateRegistry();
        $this->provider = $this->createCapturingProvider();
    }

    #[Test]
    public function generateDraftUsesTemplate(): void
    {
        $this->registerTemplate('generate_draft', 'Write about {topic} in {tone} tone, {targetWords} words', 0.9, 2000);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->generateDraft('AI Safety', 'academic', 600);

        self::assertStringContainsString('AI Safety', $this->capturedPrompt);
        self::assertStringContainsString('academic', $this->capturedPrompt);
        self::assertStringContainsString('600', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.9, $opts->temperature);
        self::assertSame(1200, $opts->maxTokens);
    }

    #[Test]
    public function summarizeUsesTemplate(): void
    {
        $this->registerTemplate('summarize', 'Summarize: {content} in {maxSentences} sentences', 0.2, 512);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->summarize('Long content here', 4);

        self::assertStringContainsString('Long content here', $this->capturedPrompt);
        self::assertStringContainsString('4', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.2, $opts->temperature);
        self::assertSame(512, $opts->maxTokens);
    }

    #[Test]
    public function suggestTitleUsesTemplate(): void
    {
        $this->registerTemplate('suggest_title', 'Titles for: {content}, count: {count}', 0.9, 300);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->suggestTitle('Test article', 7);

        self::assertStringContainsString('Test article', $this->capturedPrompt);
        self::assertStringContainsString('7', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.9, $opts->temperature);
        self::assertSame(300, $opts->maxTokens);
    }

    #[Test]
    public function suggestMetaDescriptionUsesTemplate(): void
    {
        $this->registerTemplate('suggest_meta_description', 'Meta for: {content}, max: {maxLength}', 0.4, 200);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->suggestMetaDescription('Article body', 155);

        self::assertStringContainsString('Article body', $this->capturedPrompt);
        self::assertStringContainsString('155', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.4, $opts->temperature);
        self::assertSame(200, $opts->maxTokens);
    }

    #[Test]
    public function translateContentUsesTemplate(): void
    {
        $this->registerTemplate('translate_content', 'Translate {content} from {sourceLocale} to {targetLocale}', 0.1, 1000);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->translateContent('Bonjour le monde', 'fr', 'en');

        self::assertStringContainsString('Bonjour le monde', $this->capturedPrompt);
        self::assertStringContainsString('fr', $this->capturedPrompt);
        self::assertStringContainsString('en', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.1, $opts->temperature);
    }

    #[Test]
    public function improveReadabilityUsesTemplate(): void
    {
        $this->registerTemplate('improve_readability', 'Improve: {content}', 0.6, 800);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->improveReadability('Dense text');

        self::assertStringContainsString('Dense text', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.6, $opts->temperature);
    }

    #[Test]
    public function generateOutlineUsesTemplate(): void
    {
        $this->registerTemplate('generate_outline', 'Outline for {topic}, keywords: {keywords}, audience: {targetAudience}', 0.8, 2048);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->generateOutline('Testing', ['unit', 'integration'], 'QA engineers');

        self::assertStringContainsString('Testing', $this->capturedPrompt);
        self::assertStringContainsString('unit, integration', $this->capturedPrompt);
        self::assertStringContainsString('QA engineers', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.8, $opts->temperature);
        self::assertSame(2048, $opts->maxTokens);
    }

    #[Test]
    public function expandContentUsesTemplate(): void
    {
        $this->registerTemplate('expand_content', 'Expand: {content} to {targetWords}', 0.5, 3000);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->expandContent('Brief', 1500);

        self::assertStringContainsString('Brief', $this->capturedPrompt);
        self::assertStringContainsString('1500', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.5, $opts->temperature);
        self::assertSame(3000, $opts->maxTokens);
    }

    #[Test]
    public function condenseContentUsesTemplate(): void
    {
        $this->registerTemplate('condense_content', 'Condense: {content} to {targetWords}', 0.3, 500);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->condenseContent('Verbose text', 150);

        self::assertStringContainsString('Verbose text', $this->capturedPrompt);
        self::assertStringContainsString('150', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.3, $opts->temperature);
        // condenseContent uses $targetWords * 2 for maxTokens, not template default
        self::assertSame(300, $opts->maxTokens);
    }

    #[Test]
    public function adjustToneUsesTemplate(): void
    {
        $this->registerTemplate('adjust_tone', 'Adjust to {targetTone}: {content}', 0.7, 1024);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->adjustTone('Formal text', 'casual');

        self::assertStringContainsString('Formal text', $this->capturedPrompt);
        self::assertStringContainsString('casual', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.7, $opts->temperature);
    }

    #[Test]
    public function generateFaqUsesTemplate(): void
    {
        $this->registerTemplate('generate_faq', 'FAQ for: {content}, count: {count}', 0.5, 2048);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->generateFaq('Product docs', 8);

        self::assertStringContainsString('Product docs', $this->capturedPrompt);
        self::assertStringContainsString('8', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.5, $opts->temperature);
        self::assertSame(2048, $opts->maxTokens);
    }

    #[Test]
    public function generateProductDescriptionUsesTemplate(): void
    {
        $this->registerTemplate('generate_product_description', 'Describe {productName} with {features} in {tone}', 0.8, 768);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->generateProductDescription('SuperWidget', ['fast', 'durable', 'cheap'], 'enthusiastic');

        self::assertStringContainsString('SuperWidget', $this->capturedPrompt);
        self::assertStringContainsString('fast, durable, cheap', $this->capturedPrompt);
        self::assertStringContainsString('enthusiastic', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.8, $opts->temperature);
        self::assertSame(768, $opts->maxTokens);
    }

    #[Test]
    public function extractKeywordsUsesTemplate(): void
    {
        $this->registerTemplate('extract_keywords', 'Keywords from: {content}, count: {count}', 0.2, 128);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->extractKeywords('SEO content', 15);

        self::assertStringContainsString('SEO content', $this->capturedPrompt);
        self::assertStringContainsString('15', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.2, $opts->temperature);
        self::assertSame(128, $opts->maxTokens);
    }

    #[Test]
    public function analyzeSeoScoreUsesTemplate(): void
    {
        $this->registerTemplate('analyze_seo_score', 'Analyze SEO: {content} for {targetKeyword}', 0.1, 2048);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->analyzeSeoScore('Article text', 'PHP framework');

        self::assertStringContainsString('Article text', $this->capturedPrompt);
        self::assertStringContainsString('PHP framework', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.1, $opts->temperature);
        self::assertSame(2048, $opts->maxTokens);
    }

    #[Test]
    public function suggestSlugUsesTemplate(): void
    {
        $this->registerTemplate('suggest_slug', 'Slug for: {title}', 0.2, 32);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->suggestSlug('My Amazing Article');

        self::assertStringContainsString('My Amazing Article', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.2, $opts->temperature);
        self::assertSame(32, $opts->maxTokens);
    }

    #[Test]
    public function generateAltTextUsesTemplate(): void
    {
        $this->registerTemplate('generate_alt_text', 'Alt text for image: {imageContext}, surrounding: {surroundingContent}', 0.3, 64);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->generateAltText('dog running', 'pet article');

        self::assertStringContainsString('dog running', $this->capturedPrompt);
        self::assertStringContainsString('pet article', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.3, $opts->temperature);
        self::assertSame(64, $opts->maxTokens);
    }

    #[Test]
    public function optimizeHeadingsUsesTemplate(): void
    {
        $this->registerTemplate('optimize_headings', 'Optimize headings: {content}', 0.3, 1024);
        $assistant = new ContentAssistant($this->provider, $this->registry);

        $assistant->optimizeHeadings('Content with H2');

        self::assertStringContainsString('Content with H2', $this->capturedPrompt);
        $opts = $this->capturedOptions;
        self::assertNotNull($opts);
        self::assertSame(0.3, $opts->temperature);
        self::assertSame(1024, $opts->maxTokens);
    }

    private function registerTemplate(string $name, string $template, float $temp, int $maxTokens): void
    {
        $this->registry->register(new PromptTemplate(
            name: $name,
            template: $template,
            systemPrompt: "System prompt for {$name}",
            defaultTemperature: $temp,
            defaultMaxTokens: $maxTokens,
        ));
    }

    private function createCapturingProvider(): LlmProviderInterface
    {
        $test = $this;

        return new class ($test) implements LlmProviderInterface {
            public function __construct(private readonly ContentAssistantTemplateTest $test) {}

            public function complete(string $prompt, LlmOptions $options = new LlmOptions()): LlmResponse
            {
                $this->test->capturedPrompt = $prompt;
                $this->test->capturedOptions = $options;

                return new LlmResponse('template response', 10, 20, 'stop');
            }

            public function name(): string
            {
                return 'test-template';
            }
        };
    }
}
