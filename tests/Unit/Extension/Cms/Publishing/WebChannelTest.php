<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Publishing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Internal\Publishing\WebChannel;

#[CoversClass(WebChannel::class)]
final class WebChannelTest extends TestCase
{
    private WebChannel $channel;

    protected function setUp(): void
    {
        $this->channel = new WebChannel();
    }

    #[Test]
    public function name_returns_web(): void
    {
        self::assertSame('web', $this->channel->name());
    }

    #[Test]
    public function is_always_enabled(): void
    {
        self::assertTrue($this->channel->isEnabled());
    }

    #[Test]
    public function publish_returns_success_with_path(): void
    {
        $content = $this->createContent();
        $translation = $this->createTranslation('blog/hello-world');

        $result = $this->channel->publish($content, $translation);

        self::assertTrue($result->success);
        self::assertSame('web', $result->channelName);
        self::assertSame('/blog/hello-world', $result->externalUrl);
        self::assertNull($result->errorMessage);
    }

    #[Test]
    public function unpublish_returns_success(): void
    {
        $content = $this->createContent();

        $result = $this->channel->unpublish($content);

        self::assertTrue($result->success);
        self::assertSame('web', $result->channelName);
        self::assertNull($result->externalUrl);
    }

    private function createContent(): Content
    {
        return Content::create(
            id: '01912345-6789-7abc-8def-0123456789ab',
            contentType: ContentType::Article,
            authorId: '01912345-6789-7abc-8def-0123456789cd',
        );
    }

    private function createTranslation(string $path): ContentTranslation
    {
        return new ContentTranslation(
            id: '01912345-0000-7abc-8def-aaaaaaaaaaaa',
            contentId: '01912345-6789-7abc-8def-0123456789ab',
            locale: 'en',
            title: 'Hello World',
            slugSegment: 'hello-world',
            path: $path,
            body: '<p>Hello</p>',
            excerpt: null,
            metaTitle: null,
            metaDescription: null,
            ogImageId: null,
            robots: null,
            structuredDataOverrides: null,
            readingTimeMinutes: 1,
            bodyPlaintext: 'Hello',
            headingsText: '',
            customFieldsText: '',
            taxonomyTermsText: '',
        );
    }
}
