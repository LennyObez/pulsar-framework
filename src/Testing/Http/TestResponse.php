<?php

declare(strict_types=1);

namespace Pulsar\Testing\Http;

use JsonException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;

use function array_key_exists;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_scalar;
use function json_decode;
use function json_validate;
use function sprintf;
use function str_contains;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Wraps a PSR-7 response with testing assertions.
 *
 * Provides fluent assertion methods for HTTP status, headers, JSON content,
 * and redirects: with detailed failure messages showing actual response state.
 */
#[Api(since: '1.0.0')]
final class TestResponse
{
    private readonly ResponseInterface $response;

    private readonly string $body;

    /** @var array<string, mixed>|null */
    private ?array $decodedJson = null;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
        $this->body = (string) $response->getBody();
    }

    /**
     * Get the underlying PSR-7 response.
     */
    public function response(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Get the response body as string.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Get the response status code.
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Decode the response body as JSON.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function json(): array
    {
        if ($this->decodedJson === null) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
            $this->decodedJson = $decoded;
        }

        return $this->decodedJson;
    }

    // ── Status Assertions ──────────────────────────────────────────────

    /**
     * Assert the response has a 200 OK status.
     */
    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    /**
     * Assert the response has a 201 Created status.
     */
    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    /**
     * Assert the response has a 204 No Content status.
     */
    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    /**
     * Assert the response has a 404 Not Found status.
     */
    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    /**
     * Assert the response has a 403 Forbidden status.
     */
    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    /**
     * Assert the response has a 401 Unauthorized status.
     */
    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    /**
     * Assert the response has a 422 Unprocessable Entity status.
     */
    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    /**
     * Assert the response has the given status code.
     */
    public function assertStatus(int $expected): self
    {
        $actual = $this->response->getStatusCode();

        Assert::assertSame(
            $expected,
            $actual,
            sprintf(
                "Expected status code %d, but received %d.\nResponse body: %s",
                $expected,
                $actual,
                $this->truncateBody(),
            ),
        );

        return $this;
    }

    /**
     * Assert the response status is in the 2xx success range.
     */
    public function assertSuccessful(): self
    {
        $actual = $this->response->getStatusCode();

        Assert::assertTrue(
            $actual >= 200 && $actual < 300,
            sprintf(
                "Expected a successful status code (2xx), but received %d.\nResponse body: %s",
                $actual,
                $this->truncateBody(),
            ),
        );

        return $this;
    }

    // ── Header Assertions ──────────────────────────────────────────────

    /**
     * Assert the response has a specific header.
     */
    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue(
            $this->response->hasHeader($name),
            sprintf(
                "Expected response to have header [%s], but it does not.\nHeaders: %s",
                $name,
                $this->formatHeaders(),
            ),
        );

        if ($value !== null) {
            Assert::assertSame(
                $value,
                $this->response->getHeaderLine($name),
                sprintf(
                    'Expected header [%s] to equal [%s], but got [%s].',
                    $name,
                    $value,
                    $this->response->getHeaderLine($name),
                ),
            );
        }

        return $this;
    }

    /**
     * Assert the response does NOT have a specific header.
     */
    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse(
            $this->response->hasHeader($name),
            sprintf(
                'Expected response NOT to have header [%s], but it does with value [%s].',
                $name,
                $this->response->getHeaderLine($name),
            ),
        );

        return $this;
    }

    // ── Redirect Assertions ────────────────────────────────────────────

    /**
     * Assert the response is a redirect (3xx) to the given URL.
     */
    public function assertRedirect(?string $url = null): self
    {
        $status = $this->response->getStatusCode();

        Assert::assertTrue(
            $status >= 300 && $status < 400,
            sprintf(
                'Expected a redirect status code (3xx), but received %d.',
                $status,
            ),
        );

        if ($url !== null) {
            $this->assertHeader('Location', $url);
        }

        return $this;
    }

    // ── JSON Assertions ────────────────────────────────────────────────

    /**
     * Assert the response has JSON content type.
     */
    public function assertJson(): self
    {
        $contentType = $this->response->getHeaderLine('Content-Type');

        Assert::assertTrue(
            str_contains($contentType, 'json'),
            sprintf(
                "Expected response to have JSON content type, but got [%s].\nBody: %s",
                $contentType,
                $this->truncateBody(),
            ),
        );

        Assert::assertTrue(
            json_validate($this->body),
            sprintf(
                "Expected response body to be valid JSON.\nBody: %s",
                $this->truncateBody(),
            ),
        );

        return $this;
    }

    /**
     * Assert the JSON response has the given key-value pairs (subset match).
     *
     * @param array<string, mixed> $data
     */
    public function assertJsonFragment(array $data): self
    {
        $json = $this->json();

        foreach ($data as $key => $value) {
            Assert::assertArrayHasKey(
                $key,
                $json,
                sprintf(
                    "Expected JSON to contain key [%s], but it does not.\nJSON keys: %s",
                    $key,
                    implode(', ', array_keys($json)),
                ),
            );

            Assert::assertSame(
                $value,
                $json[$key],
                sprintf(
                    'Expected JSON key [%s] to equal %s, but got %s.',
                    $key,
                    $this->formatValue($value),
                    $this->formatValue($json[$key]),
                ),
            );
        }

        return $this;
    }

    /**
     * Assert the JSON response has a value at the given dot-notation path.
     *
     * @param string $path Dot-notation path (e.g., "data.user.name")
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        $actual = $this->getJsonPath($path);

        Assert::assertSame(
            $expected,
            $actual,
            sprintf(
                'Expected JSON path [%s] to equal %s, but got %s.',
                $path,
                $this->formatValue($expected),
                $this->formatValue($actual),
            ),
        );

        return $this;
    }

    /**
     * Assert the JSON response contains the given top-level keys.
     *
     * @param list<string> $keys
     */
    public function assertJsonStructure(array $keys): self
    {
        $json = $this->json();

        foreach ($keys as $key) {
            Assert::assertArrayHasKey(
                $key,
                $json,
                sprintf(
                    "Expected JSON to have key [%s].\nActual keys: %s",
                    $key,
                    implode(', ', array_keys($json)),
                ),
            );
        }

        return $this;
    }

    /**
     * Assert the JSON array at the given key has the expected count.
     */
    public function assertJsonCount(int $count, ?string $key = null): self
    {
        $data = $key !== null ? $this->getJsonPath($key) : $this->json();

        Assert::assertTrue(
            is_array($data),
            sprintf(
                'Expected %s to be an array for count assertion, but got %s.',
                $key !== null ? sprintf('JSON path [%s]', $key) : 'JSON root',
                $this->formatValue($data),
            ),
        );

        Assert::assertCount(
            $count,
            $data,
            sprintf(
                'Expected %s to contain %d item(s), but found %d.',
                $key !== null ? sprintf('JSON path [%s]', $key) : 'JSON root',
                $count,
                count($data),
            ),
        );

        return $this;
    }

    // ── Content Assertions ─────────────────────────────────────────────

    /**
     * Assert the response body contains the given string.
     */
    public function assertSee(string $text): self
    {
        Assert::assertTrue(
            str_contains($this->body, $text),
            sprintf(
                "Expected response body to contain [%s], but it does not.\nBody: %s",
                $text,
                $this->truncateBody(),
            ),
        );

        return $this;
    }

    /**
     * Assert the response body does NOT contain the given string.
     */
    public function assertDontSee(string $text): self
    {
        Assert::assertFalse(
            str_contains($this->body, $text),
            sprintf(
                'Expected response body NOT to contain [%s], but it does.',
                $text,
            ),
        );

        return $this;
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function getJsonPath(string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $this->json();

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function truncateBody(): string
    {
        if ($this->body === '') {
            return '(empty)';
        }

        if (strlen($this->body) <= 200) {
            return $this->body;
        }

        return substr($this->body, 0, 200) . '... (truncated)';
    }

    private function formatHeaders(): string
    {
        $headers = $this->response->getHeaders();

        if ($headers === []) {
            return '(none)';
        }

        $parts = [];

        foreach ($headers as $name => $values) {
            $parts[] = sprintf('%s: %s', $name, implode(', ', $values));
        }

        return implode('; ', $parts);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return sprintf('array(%d)', count($value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}
