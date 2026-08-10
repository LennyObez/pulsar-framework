<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use NoDiscard;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Api\Api;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\SafeRedirect;
use Pulsar\Http\VaryHeader;
use Pulsar\View\Engine\TemplateEngineInterface;
use RuntimeException;

use function basename;
use function filesize;
use function implode;
use function is_array;
use function is_file;
use function is_int;
use function is_readable;
use function is_string;
use function json_encode;
use function sprintf;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * PSR-7 response: the canonical HTTP response object for Pulsar.
 *
 * Implements ResponseInterface with Pulsar-specific convenience factories.
 * Headers are stored lowercase internally with deterministic iteration order.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
class Response implements ResponseInterface
{
    private string $protocolVersion;

    /**
     * @var array<string, list<string>>
     */
    private array $headers;

    /**
     * @var array<string, string>
     */
    private array $headerNames;

    private StreamInterface $body;

    private int $statusCode;

    private string $reasonPhrase;

    /** @var array<string, list<string>>|null */
    private ?array $headersCache = null;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        int $statusCode = 200,
        string $reasonPhrase = '',
        array $headers = [],
        StreamInterface|string $body = '',
        string $protocolVersion = '1.1',
    ) {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : self::defaultReasonPhrase($statusCode);
        $this->body = is_string($body) ? new StringStream($body) : $body;
        $this->protocolVersion = $protocolVersion;

        $this->headers = [];
        $this->headerNames = [];

        foreach ($headers as $name => $value) {
            // Validate name and value at construction so a malformed
            // header cannot reach the SAPI emit path.
            HeaderValidator::assertValidName((string) $name);
            HeaderValidator::assertValidValue($value);
            $lowered = strtolower((string) $name);
            $this->headerNames[$lowered] = (string) $name;
            $this->headers[$lowered] = is_array($value) ? $value : [$value];
        }
    }

    // ── Pulsar Convenience Factories ────────────────────────────────────

    /**
     * Create a JSON response.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function json(
        array $data,
        int $status = 200,
        int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): self {
        /** @var non-empty-string $body */
        $body = json_encode($data, $options);

        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
            body: $body,
        );
    }

    /**
     * Create an HTML response.
     */
    #[NoDiscard]
    public static function html(string $html, int $status = 200): self
    {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: $html,
        );
    }

    /**
     * Create a plain text response.
     */
    #[NoDiscard]
    public static function text(string $text, int $status = 200): self
    {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'text/plain; charset=utf-8'],
            body: $text,
        );
    }

    /**
     * Create a redirect response.
     *
     * The URL is validated to prevent open redirects. Relative paths starting
     * with "/" are always allowed. Absolute URLs must use http/https and match
     * the provided allowed-hosts list.
     *
     * @param string       $url          Redirect target URL
     * @param int          $status       HTTP status code (default 302)
     * @param list<string> $allowedHosts Allowed hosts for absolute URLs
     *
     * @throws InvalidArgumentException If the URL is unsafe for redirection
     */
    #[NoDiscard]
    public static function redirect(string $url, int $status = 302, array $allowedHosts = []): self
    {
        SafeRedirect::validate($url, $allowedHosts);

        return new self(
            statusCode: $status,
            headers: ['Location' => $url],
        );
    }

    /**
     * Create a file download response.
     *
     * Sets Content-Disposition to "attachment" so the browser prompts a download.
     * If no filename is given, the basename of the path is used.
     *
     * @param string      $path     Absolute filesystem path to the file
     * @param string|null $filename Download filename presented to the user
     * @param string      $contentType MIME type (default: application/octet-stream)
     *
     * @throws InvalidArgumentException If the file does not exist or is not readable
     */
    #[NoDiscard]
    public static function download(
        string $path,
        ?string $filename = null,
        string $contentType = 'application/octet-stream',
    ): self {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(
                sprintf('File "%s" does not exist or is not readable', $path),
            );
        }

        $filename ??= basename($path);
        $stream = Stream::fromFile($path, 'rb');
        $size = filesize($path);

        $headers = [
            'Content-Type' => $contentType,
            // RFC 5987 / 6266 canonical form, refuses CRLF/quote/control injection.
            'Content-Disposition' => ContentDispositionBuilder::attachment($filename),
        ];

        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        return new self(
            statusCode: 200,
            headers: $headers,
            body: $stream,
        );
    }

    /**
     * Create an inline file response.
     *
     * Sets Content-Disposition to "inline" so the browser renders the file
     * directly (e.g. images, PDFs) rather than triggering a download.
     *
     * @param string $path        Absolute filesystem path to the file
     * @param string $contentType MIME type
     *
     * @throws InvalidArgumentException If the file does not exist or is not readable
     */
    #[NoDiscard]
    public static function file(string $path, string $contentType = 'application/octet-stream'): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(
                sprintf('File "%s" does not exist or is not readable', $path),
            );
        }

        $stream = Stream::fromFile($path, 'rb');
        $size = filesize($path);

        $headers = [
            'Content-Type' => $contentType,
            // See download() — RFC 5987/6266 canonical form.
            'Content-Disposition' => ContentDispositionBuilder::inline(basename($path)),
        ];

        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        return new self(
            statusCode: 200,
            headers: $headers,
            body: $stream,
        );
    }

    /**
     * Create an empty 204 No Content response.
     */
    #[NoDiscard]
    public static function noContent(): self
    {
        return new self(statusCode: 204);
    }

    /**
     * Create a 422 JSON response for validation errors.
     *
     * @param list<array{field: string, message: string, rule: string, code?: string}> $violations
     */
    #[NoDiscard]
    public static function validationError(array $violations): self
    {
        return self::json(
            data: [
                'error' => 'Validation Failed',
                'status' => 422,
                'violations' => $violations,
            ],
            status: 422,
        );
    }

    /**
     * Create an HTML response by rendering a template.
     *
     * Accepts an explicit {@see TemplateEngineInterface} for full dependency
     * injection, or falls back to the statically configured engine set by
     * {@see Response::setTemplateEngine()} during kernel boot.
     *
     * @param TemplateEngineInterface|string $engineOrTemplate Engine instance (explicit) or template name (static fallback)
     * @param string|array<string, mixed> $templateOrData Template name when engine is explicit, or data when using static engine
     * @param array<string, mixed>|int $dataOrStatus Data when engine is explicit, or status code when using static engine
     * @param int|array<string, string|list<string>> $statusOrHeaders Status code when engine is explicit, or unused
     * @param array<string, string|list<string>> $headers Additional response headers (only when engine is explicit)
     *
     * @throws RuntimeException If no template engine is configured (static fallback)
     */
    #[NoDiscard]
    public static function view(
        TemplateEngineInterface|string $engineOrTemplate,
        string|array $templateOrData = [],
        array|int $dataOrStatus = [],
        int|array $statusOrHeaders = 200,
        array $headers = [],
    ): self {
        // Explicit engine: view($engine, $template, $data, $status, $headers)
        if ($engineOrTemplate instanceof TemplateEngineInterface) {
            $engine = $engineOrTemplate;
            /** @var string $template */
            $template = is_string($templateOrData) ? $templateOrData : '';
            /** @var array<string, mixed> $data */
            $data = is_array($dataOrStatus) ? $dataOrStatus : [];
            /** @var int $status */
            $status = is_int($statusOrHeaders) ? $statusOrHeaders : 200;

            $html = $engine->render($template, $data);

            $response = new self(
                statusCode: $status,
                headers: ['Content-Type' => 'text/html; charset=utf-8', ...$headers],
                body: $html,
            );

            return $response;
        }

        // Static engine fallback: view($template, $data, $status)
        $engine = self::$templateEngine;

        if ($engine === null) {
            throw new RuntimeException(
                'No TemplateEngineInterface has been configured. '
                . 'Call Response::setTemplateEngine() during bootstrap or register ViewWiring.',
            );
        }

        $template = $engineOrTemplate;
        /** @var array<string, mixed> $data */
        $data = is_array($templateOrData) ? $templateOrData : [];
        /** @var int $status */
        $status = is_int($dataOrStatus) ? $dataOrStatus : 200;

        $html = $engine->render($template, $data);

        return self::html($html, $status);
    }

    /**
     * Set the template engine used by {@see Response::view()}.
     *
     * Called once during kernel boot (by ViewWiring). The engine is stored
     * statically because Response factories are static and need access
     * without requiring a container reference.
     */
    public static function setTemplateEngine(TemplateEngineInterface $engine): void
    {
        self::$templateEngine = $engine;
    }

    /**
     * Get the currently configured template engine (if any).
     *
     * Used by CMS ContentController to resolve the engine lazily after
     * the Kernel rebuilds it with extension view paths post-boot.
     */
    public static function getTemplateEngine(): ?TemplateEngineInterface
    {
        return self::$templateEngine;
    }

    /**
     * Remove the template engine reference (used in testing).
     */
    public static function clearTemplateEngine(): void
    {
        self::$templateEngine = null;
    }

    private static ?TemplateEngineInterface $templateEngine = null;

    // ── PSR-7 MessageInterface ──────────────────────────────────────────

    #[Override]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[NoDiscard]
    #[Override]
    public function withProtocolVersion(string $version): static
    {
        if ($version === $this->protocolVersion) {
            return $this;
        }

        return clone($this, ['protocolVersion' => $version]);
    }

    /**
     * @return array<string, list<string>>
     */
    #[Override]
    public function getHeaders(): array
    {
        if ($this->headersCache !== null) {
            return $this->headersCache;
        }

        $result = [];

        foreach ($this->headers as $lowered => $values) {
            $result[$this->headerNames[$lowered]] = $values;
        }

        return $this->headersCache = $result;
    }

    #[Override]
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    #[Override]
    public function getHeaderLine(string $name): string
    {
        $values = $this->getHeader($name);

        return $values === [] ? '' : implode(', ', $values);
    }

    #[NoDiscard]
    #[Override]
    public function withHeader(string $name, $value): static
    {
        // Refuse CRLF/NUL injection at the public PSR-7 boundary.
        HeaderValidator::assertValidName($name);
        /** @var list<string> $values */
        $values = is_array($value) ? array_values($value) : [$value];
        HeaderValidator::assertValidValue($values);
        $lowered = strtolower($name);

        return clone($this, [
            'headerNames' => [...$this->headerNames, $lowered => $name],
            'headers' => [...$this->headers, $lowered => $values],
            'headersCache' => null,
        ]);
    }

    #[NoDiscard]
    #[Override]
    public function withAddedHeader(string $name, $value): static
    {
        // Same validation as withHeader for the additive variant.
        HeaderValidator::assertValidName($name);
        /** @var list<string> $values */
        $values = is_array($value) ? array_values($value) : [$value];
        HeaderValidator::assertValidValue($values);
        $lowered = strtolower($name);

        if (isset($this->headers[$lowered])) {
            /** @var list<string> $merged */
            $merged = [...$this->headers[$lowered], ...$values];

            return clone($this, [
                'headersCache' => null,
                'headers' => [...$this->headers, $lowered => $merged],
            ]);
        }

        return clone($this, [
            'headersCache' => null,
            'headerNames' => [...$this->headerNames, $lowered => $name],
            'headers' => [...$this->headers, $lowered => $values],
        ]);
    }

    /**
     * Ensure the response varies on the given request header field-names.
     *
     * Field-names are merged into any existing `Vary` header — de-duplicated
     * case-insensitively, order preserved — so an existing `Vary: Cookie` is
     * kept rather than overwritten. When Vary is (or becomes) `*`, that wins.
     *
     * Call this on any response whose selection depended on a request header,
     * e.g. content or a redirect chosen from `Accept-Language`. Without it a
     * shared cache keyed on the URL alone can hand one visitor's negotiated
     * variant (or redirect) to another.
     */
    #[NoDiscard]
    public function varyOn(string ...$fieldNames): static
    {
        $existing = $this->getHeaderLine('Vary');
        $merged = VaryHeader::merge($existing, ...$fieldNames);

        if ($merged === '' || $merged === $existing) {
            return $this;
        }

        return $this->withHeader('Vary', $merged);
    }

    #[NoDiscard]
    #[Override]
    public function withoutHeader(string $name): static
    {
        $lowered = strtolower($name);

        if (!isset($this->headers[$lowered])) {
            return $this;
        }

        $headers = $this->headers;
        $headerNames = $this->headerNames;
        unset($headers[$lowered], $headerNames[$lowered]);

        return clone($this, [
            'headers' => $headers,
            'headerNames' => $headerNames,
            'headersCache' => null,
        ]);
    }

    #[Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[NoDiscard]
    #[Override]
    public function withBody(StreamInterface $body): static
    {
        return clone($this, ['body' => $body]);
    }

    // ── PSR-7 ResponseInterface ─────────────────────────────────────────

    #[Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[NoDiscard]
    #[Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return clone($this, [
            'statusCode' => $code,
            'reasonPhrase' => $reasonPhrase !== '' ? $reasonPhrase : self::defaultReasonPhrase($code),
        ]);
    }

    #[Override]
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    // ── Pulsar Convenience Inspectors ───────────────────────────────────

    /**
     * Check if the response body is empty.
     */
    public function isEmpty(): bool
    {
        return (string) $this->body === '';
    }

    /**
     * Get the Content-Length, if set.
     */
    public function contentLength(): ?int
    {
        $values = $this->getHeader('Content-Length');

        return $values !== [] ? (int) $values[0] : null;
    }

    /**
     * Get the Content-Type, if set.
     */
    public function contentType(): ?string
    {
        $values = $this->getHeader('Content-Type');

        return $values !== [] ? $values[0] : null;
    }

    /**
     * Default reason phrases per RFC 7231 / IANA registry.
     */
    private static function defaultReasonPhrase(int $code): string
    {
        $status = ResponseStatus::tryFrom($code);

        return $status?->reasonPhrase() ?? '';
    }
}
