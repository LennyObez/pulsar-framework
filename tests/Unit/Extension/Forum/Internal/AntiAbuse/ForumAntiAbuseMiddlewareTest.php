<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\AntiAbuse;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Internal\AntiAbuse\ForumAntiAbuseMiddleware;

#[CoversClass(ForumAntiAbuseMiddleware::class)]
final class ForumAntiAbuseMiddlewareTest extends TestCase
{
    private ForumAntiAbuseMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ForumAntiAbuseMiddleware();
    }

    // --- Link density checks ---

    #[Test]
    public function passesLinkDensityCheckWithNoLinks(): void
    {
        self::assertTrue($this->middleware->passesLinkDensityCheck('This is normal text without links.'));
    }

    #[Test]
    public function passesLinkDensityCheckWithEmptyBody(): void
    {
        self::assertTrue($this->middleware->passesLinkDensityCheck(''));
    }

    #[Test]
    public function passesLinkDensityCheckWithModerateLinks(): void
    {
        $body = 'Check out https://example.com for more info on this large paragraph of text that is sufficiently long to keep the ratio low.';
        self::assertTrue($this->middleware->passesLinkDensityCheck($body));
    }

    #[Test]
    public function failsLinkDensityCheckWhenMostlyLinks(): void
    {
        $body = 'https://spam.example.com/very-long-url-path https://spam.example.com/another';
        self::assertFalse($this->middleware->passesLinkDensityCheck($body));
    }

    #[Test]
    public function linkDensityRespectsCustomThreshold(): void
    {
        $strict = new ForumAntiAbuseMiddleware(maxLinkDensity: 0.1);
        $body = 'Visit https://example.com for details about this topic.';

        // With 0.1 threshold, even moderate links may fail
        $result = $strict->passesLinkDensityCheck($body);
        // The URL is ~23 chars out of ~55 chars total = ~0.42 density, should fail at 0.1
        self::assertFalse($result);
    }

    // --- Similarity checks ---

    #[Test]
    public function passesSimilarityCheckWithDifferentPosts(): void
    {
        self::assertTrue($this->middleware->passesSimilarityCheck(
            'How do I configure routing in Pulsar?',
            'What database drivers does the framework support?',
        ));
    }

    #[Test]
    public function failsSimilarityCheckWithDuplicatePost(): void
    {
        $body = 'How do I configure routing in Pulsar?';
        self::assertFalse($this->middleware->passesSimilarityCheck($body, $body));
    }

    #[Test]
    public function passesSimilarityCheckWhenNewBodyEmpty(): void
    {
        self::assertTrue($this->middleware->passesSimilarityCheck('', 'Some recent post'));
    }

    #[Test]
    public function passesSimilarityCheckWhenRecentBodyEmpty(): void
    {
        self::assertTrue($this->middleware->passesSimilarityCheck('Some new post', ''));
    }

    #[Test]
    public function similarityCheckNormalizesWhitespace(): void
    {
        $body1 = "Hello   world\n\ttabs   here";
        $body2 = 'Hello world tabs here';

        // After normalization these should be very similar
        self::assertFalse($this->middleware->passesSimilarityCheck($body1, $body2));
    }

    #[Test]
    public function similarityCheckRespectCustomThreshold(): void
    {
        $strict = new ForumAntiAbuseMiddleware(similarityThreshold: 50.0);
        self::assertFalse($strict->passesSimilarityCheck(
            'Hello world how are you?',
            'Hello world how is it going?',
        ));
    }

    // --- Honeypot checks ---

    #[Test]
    public function passesHoneypotCheckWhenEmpty(): void
    {
        self::assertTrue($this->middleware->passesHoneypotCheck(''));
    }

    #[Test]
    public function failsHoneypotCheckWhenFilled(): void
    {
        self::assertFalse($this->middleware->passesHoneypotCheck('bot-filled-value'));
    }

    #[Test]
    public function failsHoneypotCheckWithWhitespace(): void
    {
        self::assertFalse($this->middleware->passesHoneypotCheck(' '));
    }

    // --- Validate (composite) ---

    #[Test]
    public function validateReturnsEmptyArrayWhenAllPass(): void
    {
        $failures = $this->middleware->validate(
            body: 'A perfectly normal forum post.',
            honeypotValue: '',
        );

        self::assertSame([], $failures);
    }

    #[Test]
    public function validateReturnsHoneypotFailure(): void
    {
        $failures = $this->middleware->validate(
            body: 'Normal post.',
            honeypotValue: 'bot-value',
        );

        self::assertContains('honeypot', $failures);
    }

    #[Test]
    public function validateReturnsLinkDensityFailure(): void
    {
        $failures = $this->middleware->validate(
            body: 'https://spam.example.com/long-url https://spam.example.com/another-long-url',
            honeypotValue: '',
        );

        self::assertContains('link_density', $failures);
    }

    #[Test]
    public function validateReturnsSimilarityFailure(): void
    {
        $failures = $this->middleware->validate(
            body: 'This is my post content.',
            honeypotValue: '',
            recentBodies: ['This is my post content.'],
        );

        self::assertContains('similarity', $failures);
    }

    #[Test]
    public function validateReturnsMultipleFailures(): void
    {
        $failures = $this->middleware->validate(
            body: 'https://spam.example.com/long-url https://spam.example.com/another',
            honeypotValue: 'bot',
            recentBodies: ['https://spam.example.com/long-url https://spam.example.com/another'],
        );

        self::assertContains('honeypot', $failures);
        self::assertContains('link_density', $failures);
        self::assertContains('similarity', $failures);
    }

    #[Test]
    public function validateStopsAtFirstSimilarityMatch(): void
    {
        $failures = $this->middleware->validate(
            body: 'Duplicate post here',
            honeypotValue: '',
            recentBodies: ['Duplicate post here', 'Duplicate post here'],
        );

        $similarityCount = array_count_values($failures)['similarity'] ?? 0;
        self::assertSame(1, $similarityCount);
    }

    #[Test]
    public function validateWithEmptyRecentBodiesSkipsSimilarity(): void
    {
        $failures = $this->middleware->validate(
            body: 'Some post content.',
            honeypotValue: '',
            recentBodies: [],
        );

        self::assertNotContains('similarity', $failures);
    }
}
