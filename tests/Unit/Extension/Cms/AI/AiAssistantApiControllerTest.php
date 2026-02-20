<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use Pulsar\Extension\Cms\AI\LlmOptions;
use Pulsar\Extension\Cms\AI\LlmProviderInterface;
use Pulsar\Extension\Cms\AI\LlmResponse;
use Pulsar\Extension\Cms\Config\AiConfig;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\Api\AiAssistantApiController;
use Pulsar\Extension\Cms\Internal\Http\AiRequestParser;

use function json_decode;

#[CoversClass(AiAssistantApiController::class)]
final class AiAssistantApiControllerTest extends TestCase
{
    #[Test]
    public function returns_403_when_ai_disabled(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: false));
        $controller = $this->createController($config);

        $request = $this->createRequest(['topic' => 'test']);
        $response = $controller->generateDraft($request);

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('AI assistant is not enabled', $body['error']);
    }

    #[Test]
    public function generate_draft_returns_422_when_topic_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest([]);
        $response = $controller->generateDraft($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: topic', $body['error']);
    }

    #[Test]
    public function generate_draft_returns_200_with_content(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Draft content', 50, 100, 'stop'));

        $request = $this->createRequest(['topic' => 'PHP testing']);
        $response = $controller->generateDraft($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Draft content', $body['content']);
        self::assertIsArray($body['usage']);
        self::assertSame(50, $body['usage']['input_tokens']);
        self::assertSame(100, $body['usage']['output_tokens']);
        self::assertSame('stop', $body['finish_reason']);
    }

    #[Test]
    public function summarize_returns_422_when_content_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest([]);
        $response = $controller->summarize($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function summarize_returns_200_with_content(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Summary text', 30, 15, 'stop'));

        $request = $this->createRequest(['content' => 'Long article text']);
        $response = $controller->summarize($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Summary text', $body['content']);
    }

    #[Test]
    public function suggest_titles_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse("Title 1\nTitle 2", 20, 10, 'stop'));

        $request = $this->createRequest(['content' => 'Article content']);
        $response = $controller->suggestTitles($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function suggest_meta_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Meta description', 20, 10, 'stop'));

        $request = $this->createRequest(['content' => 'Article content']);
        $response = $controller->suggestMeta($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function translate_returns_422_when_locales_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest(['content' => 'Hello']);
        $response = $controller->translate($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: sourceLocale', $body['error']);
    }

    #[Test]
    public function translate_returns_200_with_all_fields(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Bonjour', 10, 5, 'stop'));

        $request = $this->createRequest([
            'content' => 'Hello',
            'sourceLocale' => 'en',
            'targetLocale' => 'fr',
        ]);
        $response = $controller->translate($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Bonjour', $body['content']);
    }

    #[Test]
    public function improve_readability_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Improved text', 30, 25, 'stop'));

        $request = $this->createRequest(['content' => 'Complex text']);
        $response = $controller->improveReadability($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function error_response_returns_502(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('API error', 0, 0, 'error'));

        $request = $this->createRequest(['topic' => 'test']);
        $response = $controller->generateDraft($request);

        self::assertSame(502, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('error', $body['finish_reason']);
    }

    #[Test]
    public function generate_outline_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Outline content', 30, 40, 'stop'));

        $request = $this->createRequest(['topic' => 'PHP design patterns', 'keywords' => ['SOLID', 'DI'], 'targetAudience' => 'developers']);
        $response = $controller->generateOutline($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Outline content', $body['content']);
    }

    #[Test]
    public function expand_content_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Expanded text', 20, 80, 'stop'));

        $request = $this->createRequest(['content' => 'Short text']);
        $response = $controller->expandContent($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Expanded text', $body['content']);
    }

    #[Test]
    public function condense_content_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Condensed text', 40, 15, 'stop'));

        $request = $this->createRequest(['content' => 'Long verbose text']);
        $response = $controller->condenseContent($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Condensed text', $body['content']);
    }

    #[Test]
    public function adjust_tone_returns_422_when_target_tone_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest(['content' => 'Some text']);
        $response = $controller->adjustTone($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: targetTone', $body['error']);
    }

    #[Test]
    public function adjust_tone_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Humorous text', 20, 25, 'stop'));

        $request = $this->createRequest(['content' => 'Some text', 'targetTone' => 'humorous']);
        $response = $controller->adjustTone($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Humorous text', $body['content']);
    }

    #[Test]
    public function generate_faq_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Q: What?\nA: This.', 20, 30, 'stop'));

        $request = $this->createRequest(['content' => 'Article content', 'count' => 3]);
        $response = $controller->generateFaq($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function generate_product_description_returns_422_when_features_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest(['productName' => 'Widget']);
        $response = $controller->generateProductDescription($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: features', $body['error']);
    }

    #[Test]
    public function generate_product_description_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Product desc', 15, 30, 'stop'));

        $request = $this->createRequest(['productName' => 'Widget Pro', 'features' => ['fast', 'durable']]);
        $response = $controller->generateProductDescription($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Product desc', $body['content']);
    }

    #[Test]
    public function extract_keywords_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse("keyword1\nkeyword2", 10, 5, 'stop'));

        $request = $this->createRequest(['content' => 'SEO article content']);
        $response = $controller->extractKeywords($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function analyze_seo_score_returns_422_when_target_keyword_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest(['content' => 'Some content']);
        $response = $controller->analyzeSeoScore($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: targetKeyword', $body['error']);
    }

    #[Test]
    public function analyze_seo_score_returns_json_analysis(): void
    {
        $jsonContent = '{"score":85,"keyword_density":"2.5%","title_optimization":"good","meta_description_quality":"fair","heading_structure":"good","readability":"good","suggestions":["Add more internal links"]}';
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse($jsonContent, 40, 60, 'stop'));

        $request = $this->createRequest(['content' => 'Content about PHP', 'targetKeyword' => 'PHP']);
        $response = $controller->analyzeSeoScore($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertArrayHasKey('analysis', $body);
        /** @var array{score: int} $analysis */
        $analysis = $body['analysis'];
        self::assertSame(85, $analysis['score']);
        self::assertArrayHasKey('usage', $body);
    }

    #[Test]
    public function analyze_seo_score_falls_back_on_invalid_json(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Not valid JSON', 10, 20, 'stop'));

        $request = $this->createRequest(['content' => 'Content about PHP', 'targetKeyword' => 'PHP']);
        $response = $controller->analyzeSeoScore($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertArrayHasKey('content', $body);
        self::assertSame('Not valid JSON', $body['content']);
    }

    #[Test]
    public function suggest_slug_returns_422_when_title_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest([]);
        $response = $controller->suggestSlug($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: title', $body['error']);
    }

    #[Test]
    public function suggest_slug_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('my-great-article', 5, 3, 'stop'));

        $request = $this->createRequest(['title' => 'My Great Article']);
        $response = $controller->suggestSlug($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('my-great-article', $body['content']);
    }

    #[Test]
    public function generate_alt_text_returns_422_when_image_context_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config);

        $request = $this->createRequest(['surroundingContent' => 'Article about pets']);
        $response = $controller->generateAltText($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: imageContext', $body['error']);
    }

    #[Test]
    public function generate_alt_text_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('A fluffy orange cat', 10, 8, 'stop'));

        $request = $this->createRequest(['imageContext' => 'Photo of a cat', 'surroundingContent' => 'Article about pets']);
        $response = $controller->generateAltText($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('A fluffy orange cat', $body['content']);
    }

    #[Test]
    public function optimize_headings_returns_200(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('Optimized headings', 20, 25, 'stop'));

        $request = $this->createRequest(['content' => 'Content with headings']);
        $response = $controller->optimizeHeadings($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function generate_serp_preview_returns_422_when_title_missing(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: false));
        $controller = $this->createController($config);

        $request = $this->createRequest(['metaDescription' => 'Desc', 'url' => 'https://example.com']);
        $response = $controller->generateSerpPreview($request);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('Missing required field: title', $body['error']);
    }

    #[Test]
    public function generate_serp_preview_returns_200_without_ai(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: false));
        $controller = $this->createController($config);

        $request = $this->createRequest([
            'title' => 'My Page Title',
            'metaDescription' => 'A great page about PHP frameworks.',
            'url' => 'https://example.com/my-page',
        ]);
        $response = $controller->generateSerpPreview($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeBody($response);
        self::assertSame('My Page Title', $body['title']);
        self::assertSame('A great page about PHP frameworks.', $body['description']);
        self::assertSame('https://example.com/my-page', $body['url']);
        self::assertSame('example.com/my-page', $body['display_url']);
    }

    #[Test]
    public function analyze_seo_score_returns_502_on_error(): void
    {
        $config = new CmsConfig(ai: new AiConfig(enabled: true, apiKey: 'test'));
        $controller = $this->createController($config, new LlmResponse('API error', 0, 0, 'error'));

        $request = $this->createRequest(['content' => 'Some content', 'targetKeyword' => 'PHP']);
        $response = $controller->analyzeSeoScore($request);

        self::assertSame(502, $response->getStatusCode());
    }

    private function createController(CmsConfig $config, ?LlmResponse $response = null): AiAssistantApiController
    {
        $llmResponse = $response ?? new LlmResponse('', 0, 0, 'stop');

        $provider = new class ($llmResponse) implements LlmProviderInterface {
            public function __construct(private readonly LlmResponse $response) {}

            public function complete(string $prompt, LlmOptions $options = new LlmOptions()): LlmResponse
            {
                return $this->response;
            }

            public function name(): string
            {
                return 'test';
            }
        };

        $assistant = new ContentAssistant($provider);
        $parser = new AiRequestParser($config);

        return new AiAssistantApiController($assistant, $parser);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRequest(array $body): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/v1/ai/test');

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn('');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getUri')->willReturn($uri);
        $request->method('getBody')->willReturn($stream);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeaderLine')->willReturn('application/json');

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(\Pulsar\Http\Message\Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        return $data;
    }
}
