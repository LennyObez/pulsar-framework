<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Api;
use stdClass;
use WeakMap;

use function array_all;
use function array_diff_key;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_validate;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * PSR-7 server request — the canonical HTTP request object for Pulsar.
 *
 * Implements ServerRequestInterface with Pulsar-specific convenience methods.
 * Headers are stored lowercase internally with deterministic iteration order.
 */
#[Api(since: '1.0.0-rc.11')]
class ServerRequest implements ServerRequestInterface
{
    private string $protocolVersion;

    /**
     * Headers stored with lowercase keys.
     *
     * @var array<string, list<string>>
     */
    private array $headers;

    /**
     * Original case mapping for headers.
     *
     * @var array<string, string>
     */
    private array $headerNames;

    private StreamInterface $body;

    private string $requestTarget = '';

    private string $method;

    private UriInterface $uri;

    /** @var array<string, mixed> */
    private array $serverParams;

    /** @var array<string, mixed> */
    private array $cookieParams;

    /** @var array<string, mixed> */
    private array $queryParams;

    /** @var array<UploadedFileInterface> */
    private array $uploadedFiles;

    /** @var null|array<string, mixed>|object */
    private null|array|object $parsedBody;

    /** @var array<string, mixed> */
    private array $attributes;

    /** @var array<string, list<string>>|null */
    private ?array $headersCache = null;

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $cookieParams
     * @param array<string, mixed> $queryParams
     * @param array<UploadedFileInterface> $uploadedFiles
     * @param null|array<string, mixed>|object $parsedBody
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string $uri = '',
        array $headers = [],
        StreamInterface|string $body = '',
        string $protocolVersion = '1.1',
        array $serverParams = [],
        array $cookieParams = [],
        array $queryParams = [],
        array $uploadedFiles = [],
        null|array|object $parsedBody = null,
        array $attributes = [],
    ) {
        $this->method = $method;
        $this->uri = is_string($uri) ? Uri::fromString($uri) : $uri;
        $this->body = is_string($body) ? new StringStream($body) : $body;
        $this->protocolVersion = $protocolVersion;
        $this->serverParams = $serverParams;
        $this->cookieParams = $cookieParams;
        $this->queryParams = $queryParams;
        $this->uploadedFiles = $uploadedFiles;
        $this->parsedBody = $parsedBody;
        $this->attributes = $attributes;

        // Normalize headers
        $this->headers = [];
        $this->headerNames = [];

        foreach ($headers as $name => $value) {
            $lowered = strtolower($name);
            $this->headerNames[$lowered] = $name;
            $this->headers[$lowered] = is_array($value) ? $value : [$value];
        }

        // Set Host header from URI if not present
        if (!isset($this->headers['host']) && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();

            $port = $this->uri->getPort();

            if ($port !== null) {
                $host .= ':' . $port;
            }

            $this->headerNames['host'] = 'Host';
            $this->headers['host'] = [$host];
        }
    }

    /**
     * Create a ServerRequest from PHP superglobals.
     *
     * @param array<string, mixed>|null $server
     * @param array<string, mixed>|null $get
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>|null $cookies
     * @param array<string, mixed>|null $files
     */
    #[NoDiscard]
    public static function fromGlobals(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $files = null,
    ): self {
        /** @var array<string, mixed> $serverData */
        $serverData = $server ?? $_SERVER;
        /** @var array<string, mixed> $getData */
        $getData = $get ?? $_GET;
        /** @var array<string, mixed> $postData */
        $postData = $post ?? $_POST;
        /** @var array<string, mixed> $cookieData */
        $cookieData = $cookies ?? $_COOKIE;
        /** @var array<string, mixed> $fileData */
        $fileData = $files ?? $_FILES;

        $requestMethod = $serverData['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($requestMethod) ? $requestMethod : 'GET';

        $requestUri = $serverData['REQUEST_URI'] ?? '/';
        $uriString = is_string($requestUri) ? $requestUri : '/';

        // Build URI from server params
        $scheme = (!empty($serverData['HTTPS']) && $serverData['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = '';
        $port = null;

        if (isset($serverData['HTTP_HOST']) && is_string($serverData['HTTP_HOST'])) {
            $hostParts = explode(':', $serverData['HTTP_HOST']);
            $host = $hostParts[0];

            if (isset($hostParts[1])) {
                $port = (int) $hostParts[1];
            }
        } elseif (isset($serverData['SERVER_NAME']) && is_string($serverData['SERVER_NAME'])) {
            $host = $serverData['SERVER_NAME'];
        }

        if ($port === null && isset($serverData['SERVER_PORT'])) {
            /** @var int|string $serverPort */
            $serverPort = $serverData['SERVER_PORT'];
            $port = (int) $serverPort;
        }

        // Parse path and query from REQUEST_URI
        $path = $uriString;
        $queryString = '';
        $queryPos = strpos($uriString, '?');

        if ($queryPos !== false) {
            $path = substr($uriString, 0, $queryPos);
            $queryString = substr($uriString, $queryPos + 1);
        }

        $uri = new Uri(
            scheme: $scheme,
            host: strtolower($host),
            port: $port,
            path: $path,
            query: $queryString,
        );

        $protocol = $serverData['SERVER_PROTOCOL'] ?? null;
        $protocolVersion = is_string($protocol) ? str_replace('HTTP/', '', $protocol) : '1.1';

        // Extract headers from $_SERVER
        $headers = self::extractHeadersFromServer($serverData);

        // Read body from php://input
        $body = Stream::fromFile('php://input', 'rb');

        /** @var array<UploadedFileInterface> $uploadedFiles */
        $uploadedFiles = self::normalizeFiles($fileData);

        return new self(
            method: $method,
            uri: $uri,
            headers: $headers,
            body: $body,
            protocolVersion: $protocolVersion,
            serverParams: $serverData,
            cookieParams: $cookieData,
            queryParams: $getData,
            uploadedFiles: $uploadedFiles,
            parsedBody: !empty($postData) ? $postData : null,
        );
    }

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
        /** @var list<string> $values */
        $values = is_array($value) ? $value : [$value];
        $lowered = strtolower($name);

        $new = clone $this;
        $new->headerNames[$lowered] = $name;
        $new->headers[$lowered] = $values;
        $new->headersCache = null;

        return $new;
    }

    #[NoDiscard]
    #[Override]
    public function withAddedHeader(string $name, $value): static
    {
        /** @var list<string> $values */
        $values = is_array($value) ? $value : [$value];
        $lowered = strtolower($name);

        $new = clone $this;
        $new->headersCache = null;

        if (isset($new->headers[$lowered])) {
            /** @var list<string> $merged */
            $merged = [...$new->headers[$lowered], ...$values];
            $new->headers[$lowered] = $merged;
        } else {
            $new->headerNames[$lowered] = $name;
            $new->headers[$lowered] = $values;
        }

        return $new;
    }

    #[NoDiscard]
    #[Override]
    public function withoutHeader(string $name): static
    {
        $lowered = strtolower($name);

        if (!isset($this->headers[$lowered])) {
            return $this;
        }

        $new = clone $this;
        unset($new->headers[$lowered], $new->headerNames[$lowered]);
        $new->headersCache = null;

        return $new;
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

    // ── PSR-7 RequestInterface ──────────────────────────────────────────

    #[Override]
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== '') {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();

        if ($target === '') {
            $target = '/';
        }

        $query = $this->uri->getQuery();

        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }

    #[NoDiscard]
    #[Override]
    public function withRequestTarget(string $requestTarget): static
    {
        return clone($this, ['requestTarget' => $requestTarget]);
    }

    #[Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[NoDiscard]
    #[Override]
    public function withMethod(string $method): static
    {
        return clone($this, ['method' => $method]);
    }

    #[Override]
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    #[NoDiscard]
    #[Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || !$this->hasHeader('Host')) {
            $host = $uri->getHost();

            if ($host !== '') {
                $uriPort = $uri->getPort();

                if ($uriPort !== null) {
                    $host .= ':' . $uriPort;
                }

                $new->headerNames['host'] = 'Host';
                $new->headers['host'] = [$host];
                $new->headersCache = null;
            }
        }

        return $new;
    }

    // ── PSR-7 ServerRequestInterface ────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array<array-key, mixed> $cookies
     */
    #[NoDiscard]
    #[Override]
    public function withCookieParams(array $cookies): static
    {
        /** @var array<string, mixed> $cookies */
        return clone($this, ['cookieParams' => $cookies]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[NoDiscard]
    #[Override]
    public function withQueryParams(array $query): static
    {
        /** @var array<string, mixed> $query */
        return clone($this, ['queryParams' => $query]);
    }

    /**
     * @return array<UploadedFileInterface>
     */
    #[Override]
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     */
    #[NoDiscard]
    #[Override]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        /** @var array<UploadedFileInterface> $uploadedFiles */
        return clone($this, ['uploadedFiles' => $uploadedFiles]);
    }

    /**
     * @return null|array<string, mixed>|object
     */
    #[Override]
    public function getParsedBody(): null|array|object
    {
        return $this->parsedBody;
    }

    /**
     * @param null|array<array-key, mixed>|object $data
     */
    #[NoDiscard]
    #[Override]
    public function withParsedBody(mixed $data): static
    {
        if ($data !== null && !is_array($data) && !($data instanceof stdClass)) {
            throw new InvalidArgumentException('Parsed body must be array, object, or null.');
        }

        /** @var null|array<string, mixed>|stdClass $data */
        return clone($this, ['parsedBody' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    #[Override]
    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    #[NoDiscard]
    #[Override]
    public function withAttribute(string $name, $value): static
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    #[NoDiscard]
    #[Override]
    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }

    // ── Pulsar Convenience Methods ──────────────────────────────────────

    /**
     * Get a single header value (first value).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $values = $this->getHeader($name);

        return $values[0] ?? $default;
    }

    /**
     * Get a query parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Get a POST parameter from parsed body.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        if (!is_array($this->parsedBody)) {
            return $default;
        }

        return $this->parsedBody[$key] ?? $default;
    }

    /**
     * Get a cookie value.
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookieParams[$key] ?? $default;
    }

    /**
     * Get a server parameter.
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->serverParams[$key] ?? $default;
    }

    /**
     * Convenience alias for getAttribute().
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get a route parameter from attributes.
     */
    public function routeParam(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Decode the request body as JSON.
     *
     * Results are memoized per-instance via a WeakMap so that repeated calls
     * do not re-decode the body.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        /**
         * @var WeakMap<self, array<string, mixed>>|null $cache
         */
        static $cache = null;

        if ($cache === null) {
            /** @var WeakMap<self, array<string, mixed>> $map */
            $map = new WeakMap();
            $cache = $map;
        }

        if (isset($cache[$this])) {
            /** @var array<string, mixed> */
            return $cache[$this];
        }

        $result = $this->decodeJsonBody();
        $cache[$this] = $result;

        return $result;
    }

    /**
     * Merge all input sources: query + parsed body + json (json > post > query precedence).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $post = is_array($this->parsedBody) ? $this->parsedBody : [];

        return [...$this->queryParams, ...$post, ...$this->json()];
    }

    /**
     * Get a value from the merged input (json > post > query).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Check that all given keys exist in the merged input.
     */
    public function has(string ...$keys): bool
    {
        $all = $this->all();

        return array_all($keys, static fn(string $key): bool => array_key_exists($key, $all));
    }

    /**
     * Check that all given keys exist and are not empty string or null.
     */
    public function filled(string ...$keys): bool
    {
        $all = $this->all();

        return array_all(
            $keys,
            static fn(string $key): bool => array_key_exists($key, $all) && $all[$key] !== '' && $all[$key] !== null,
        );
    }

    /**
     * Return a subset of the merged input for the given keys.
     *
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Return the merged input minus the given keys.
     *
     * @return array<string, mixed>
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Check if the client prefers a JSON response.
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('Accept');

        return $accept !== null && str_contains($accept, 'application/json');
    }

    /**
     * Check if this is an AJAX/XHR request.
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Check if the request is over HTTPS.
     */
    public function isSecure(): bool
    {
        return $this->uri->getScheme() === 'https';
    }

    /**
     * Get the client's preferred content type from Accept header.
     */
    public function preferredContentType(): ?string
    {
        $accept = $this->header('Accept');

        if ($accept === null) {
            return null;
        }

        $types = explode(',', $accept);
        $preferred = trim($types[0]);

        $pos = strpos($preferred, ';');

        if ($pos !== false) {
            $preferred = substr($preferred, 0, $pos);
        }

        return trim($preferred);
    }

    /**
     * Buffer the body stream into a rewindable in-memory stream.
     *
     * @param int $maxBytes Maximum body size to buffer (default 8MB)
     *
     * @throws BodyTooLargeException If the body exceeds maxBytes
     */
    #[NoDiscard]
    public function bufferBody(int $maxBytes = 8_388_608): static
    {
        $body = $this->getBody();

        // Check size hint first
        $size = $body->getSize();

        if ($size !== null && $size > $maxBytes) {
            throw BodyTooLargeException::exceedsLimit($size, $maxBytes);
        }

        // Read incrementally to enforce limit
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $content = '';
        $bytesRead = 0;

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            $bytesRead += strlen($chunk);

            if ($bytesRead > $maxBytes) {
                throw BodyTooLargeException::exceedsLimit($bytesRead, $maxBytes);
            }

            $content .= $chunk;
        }

        return $this->withBody(new BufferStream($content));
    }

    /**
     * Return a new request with multiple attributes merged at once.
     *
     * @param array<string, mixed> $attributes
     */
    #[NoDiscard]
    public function withAttributes(array $attributes): static
    {
        return clone($this, ['attributes' => [...$this->attributes, ...$attributes]]);
    }

    // ── Private ─────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(): array
    {
        $contentType = $this->header('Content-Type');

        if ($contentType === null || !str_contains($contentType, 'application/json')) {
            return [];
        }

        $body = (string) $this->getBody();

        if ($body === '' || !json_validate($body)) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> */
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * Extract HTTP headers from a $_SERVER-style array.
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Normalize $_FILES into a tree of UploadedFile instances.
     *
     * @param array<string, mixed> $files
     *
     * @return array<string, UploadedFile|array<string, UploadedFile>>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFile) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                if (isset($value['tmp_name'])) {
                    /** @var array<string, mixed> $value */
                    $normalized[$key] = self::createUploadedFileFromSpec($value);
                } else {
                    /** @var array<string, mixed> $value */
                    $normalized[$key] = self::normalizeFiles($value);
                }
            }
        }

        /** @var array<string, UploadedFile|array<string, UploadedFile>> */
        return $normalized;
    }

    /**
     * Create UploadedFile(s) from a $_FILES spec array.
     *
     * @param array<string, mixed> $value
     *
     * @return UploadedFile|array<string, UploadedFile>
     */
    private static function createUploadedFileFromSpec(array $value): UploadedFile|array
    {
        if (is_array($value['tmp_name'])) {
            $files = [];

            /** @var array<string, string> $tmpNames */
            $tmpNames = $value['tmp_name'];
            /** @var array<string, int> $sizes */
            $sizes = $value['size'] ?? [];
            /** @var array<string, int> $errors */
            $errors = $value['error'] ?? [];
            /** @var array<string, string> $names */
            $names = $value['name'] ?? [];
            /** @var array<string, string> $types */
            $types = $value['type'] ?? [];

            foreach (array_keys($tmpNames) as $idx) {
                $files[$idx] = new UploadedFile(
                    streamOrFile: $tmpNames[$idx] ?? '',
                    size: $sizes[$idx] ?? null,
                    error: $errors[$idx] ?? UPLOAD_ERR_NO_FILE,
                    clientFilename: $names[$idx] ?? null,
                    clientMediaType: $types[$idx] ?? null,
                );
            }

            return $files;
        }

        /** @var string $tmpName */
        $tmpName = $value['tmp_name'];
        /** @var int|string|null $size */
        $size = $value['size'] ?? null;
        /** @var int|string $error */
        $error = $value['error'] ?? UPLOAD_ERR_NO_FILE;

        return new UploadedFile(
            streamOrFile: $tmpName,
            size: $size !== null ? (int) $size : null,
            error: (int) $error,
            clientFilename: isset($value['name']) && is_string($value['name']) ? $value['name'] : null,
            clientMediaType: isset($value['type']) && is_string($value['type']) ? $value['type'] : null,
        );
    }
}
