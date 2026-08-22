<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Closure;
use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function str_contains;

/**
 * Fake HTTP client for testing outgoing HTTP requests without network calls.
 *
 * Register stub responses for URLs and then assert that requests were made
 * with expected methods, URLs, and payloads.
 *
 * Usage:
 *   $http = HttpClientFake::create();
 *   $http->stub('https://api.example.com/users', new FakeHttpResponse(200, '{"ok":true}'));
 *   // ... run code that makes HTTP requests ...
 *   $http->assertSent('GET', 'https://api.example.com/users');
 * @api
 */
#[Api(since: '1.0.0')]
final class HttpClientFake
{
    /** @var list<FakeHttpRequest> */
    private array $recorded = [];

    /** @var array<string, FakeHttpResponse> */
    private array $stubs = [];

    private ?FakeHttpResponse $defaultResponse = null;

    private function __construct() {}

    /**
     * Create a fake HTTP client.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Register a stub response for a URL pattern.
     *
     * @return $this
     */
    public function stub(string $urlPattern, FakeHttpResponse $response): self
    {
        $this->stubs[$urlPattern] = $response;

        return $this;
    }

    /**
     * Set a default response for all unmatched URLs.
     *
     * @return $this
     */
    public function stubDefault(FakeHttpResponse $response): self
    {
        $this->defaultResponse = $response;

        return $this;
    }

    /**
     * Simulate sending an HTTP request. Returns the matching stub response.
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, string $body = '', array $headers = []): FakeHttpResponse
    {
        $request = new FakeHttpRequest($method, $url, $body, $headers);
        $this->recorded[] = $request;

        // Check exact match first, then pattern match
        foreach ($this->stubs as $pattern => $response) {
            if ($pattern === $url || str_contains($url, $pattern)) {
                return $response;
            }
        }

        return $this->defaultResponse ?? new FakeHttpResponse(200, '');
    }

    /**
     * Assert that a request was sent matching the given method and URL.
     */
    public function assertSent(string $method, ?string $url = null): void
    {
        $matching = $this->findMatchingRequests($method, $url);

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected an HTTP %s request%s to be sent, but it was not.\nRecorded requests: %s",
                $method,
                $url !== null ? sprintf(' to [%s]', $url) : '',
                $this->formatRecorded(),
            ),
        );
    }

    /**
     * Assert that a request was sent matching a callback.
     *
     * @param Closure(FakeHttpRequest): bool $callback
     */
    public function assertSentWith(Closure $callback): void
    {
        $matching = array_filter($this->recorded, $callback);

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected an HTTP request matching callback, but none matched.\nRecorded requests: %s",
                $this->formatRecorded(),
            ),
        );
    }

    /**
     * Assert that no request was sent for the given method and URL.
     */
    public function assertNotSent(string $method, ?string $url = null): void
    {
        $matching = $this->findMatchingRequests($method, $url);

        Assert::assertEmpty(
            $matching,
            sprintf(
                'Expected no HTTP %s request%s to be sent, but %d were.',
                $method,
                $url !== null ? sprintf(' to [%s]', $url) : '',
                count($matching),
            ),
        );
    }

    /**
     * Assert that no requests were sent at all.
     */
    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            sprintf(
                "Expected no HTTP requests to be sent, but %d were.\nRecorded: %s",
                count($this->recorded),
                $this->formatRecorded(),
            ),
        );
    }

    /**
     * Assert the exact number of requests sent.
     */
    public function assertSentCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recorded,
            sprintf(
                'Expected %d HTTP request(s), but %d were sent.',
                $count,
                count($this->recorded),
            ),
        );
    }

    /**
     * Get all recorded requests.
     *
     * @return list<FakeHttpRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Reset all recorded state and stubs.
     */
    public function reset(): void
    {
        $this->recorded = [];
        $this->stubs = [];
        $this->defaultResponse = null;
    }

    /**
     * @return list<FakeHttpRequest>
     */
    private function findMatchingRequests(string $method, ?string $url): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn(FakeHttpRequest $r): bool =>
                $r->method === $method
                && ($url === null || $r->url === $url || str_contains($r->url, $url)),
        ));
    }

    private function formatRecorded(): string
    {
        if ($this->recorded === []) {
            return '(none)';
        }

        $parts = [];

        foreach ($this->recorded as $request) {
            $parts[] = sprintf('%s %s', $request->method, $request->url);
        }

        return implode(', ', $parts);
    }
}
