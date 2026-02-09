<?php

declare(strict_types=1);

namespace Pulsar\Http;

use function array_diff_key;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use function json_validate;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;

use function str_contains;

use WeakMap;

/**
 * Immutable HTTP request value object.
 */
#[Api(since: '1.0.0')]
readonly class Request
{
    /**
     * @param array<string, mixed> $query   GET parameters
     * @param array<string, mixed> $post    POST parameters
     * @param array<string, mixed> $cookies Cookie data
     * @param array<string, mixed> $server  Server parameters
     * @param array<string, mixed> $attributes Custom attributes
     */
    public function __construct(
        public Method $method,
        public string $uri,
        public string $path,
        public string $queryString,
        public HeaderBag $headers,
        public string $body,
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $server = [],
        public array $attributes = [],
        public string $protocolVersion = '1.1',
    ) {}

    /**
     * Get a query parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a POST parameter.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get a cookie value.
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get a server parameter.
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Get a custom attribute.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get a header value.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers->first($name, $default);
    }

    /**
     * Return a new request with an added attribute.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement -- Psalm does not yet infer clone() return type
     */
    #[NoDiscard]
    public function withAttribute(string $key, mixed $value): self
    {
        return clone($this, ['attributes' => [...$this->attributes, $key => $value]]);
    }

    /**
     * Return a new request with multiple attributes merged at once.
     *
     * More efficient than chaining multiple `withAttribute()` calls because
     * only a single clone is performed regardless of the number of attributes.
     *
     * @param array<string, mixed> $attributes Attributes to merge (overwrites existing keys)
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withAttributes(array $attributes): self
    {
        return clone($this, ['attributes' => [...$this->attributes, ...$attributes]]);
    }

    /**
     * Return a new request without the specified attribute.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withoutAttribute(string $key): self
    {
        $attributes = $this->attributes;
        unset($attributes[$key]);

        return clone($this, ['attributes' => $attributes]);
    }

    /**
     * Decode the request body as JSON when Content-Type contains application/json.
     *
     * Returns an empty array on invalid JSON, empty body, or wrong content type.
     * Results are memoized per-instance via a WeakMap so that repeated calls
     * (e.g. `all()`, `input()`, `has()`) do not re-decode the body.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        /**
         * Function-scoped static WeakMap avoids the readonly-class restriction
         * on static properties while still providing per-instance memoization.
         *
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
     * Perform the actual JSON body decoding (no caching).
     *
     * @return array<string, mixed>
     */
    private function decodeJsonBody(): array
    {
        $contentType = $this->header('Content-Type');
        if ($contentType === null || !str_contains($contentType, 'application/json')) {
            return [];
        }

        if ($this->body === '' || !json_validate($this->body)) {
            return [];
        }

        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> */
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * Merge all input sources: query + post + json (json > post > query precedence).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [...$this->query, ...$this->post, ...$this->json()];
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
     * Check if the client prefers a JSON response (Accept header contains application/json).
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
        $https = $this->server('HTTPS');
        return $https !== null && $https !== 'off';
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

        // Remove quality value
        $pos = strpos($preferred, ';');
        if ($pos !== false) {
            $preferred = substr($preferred, 0, $pos);
        }

        return $preferred;
    }

    /**
     * Create a Request from PHP superglobals.
     *
     * @param array<string, mixed>|null $get
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>|null $cookies
     * @param array<string, mixed>|null $server
     */
    #[NoDiscard]
    public static function fromGlobals(
        ?array $get = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $server = null,
    ): self {
        /** @var array<string, mixed> $serverData */
        $serverData = $server ?? $_SERVER;
        /** @var array<string, mixed> $getData */
        $getData = $get ?? $_GET;
        /** @var array<string, mixed> $postData */
        $postData = $post ?? $_POST;
        /** @var array<string, mixed> $cookieData */
        $cookieData = $cookies ?? $_COOKIE;

        $requestMethod = $serverData['REQUEST_METHOD'] ?? 'GET';
        $method = Method::fromString(is_string($requestMethod) ? $requestMethod : 'GET');

        $requestUri = $serverData['REQUEST_URI'] ?? '/';
        $uri = is_string($requestUri) ? $requestUri : '/';

        $queryStr = $serverData['QUERY_STRING'] ?? '';
        $queryString = is_string($queryStr) ? $queryStr : '';

        $protocol = $serverData['SERVER_PROTOCOL'] ?? null;
        $protocolVersion = is_string($protocol)
            ? str_replace('HTTP/', '', $protocol)
            : '1.1';

        // Extract path from URI
        $path = $uri;
        $queryPos = strpos($path, '?');
        if ($queryPos !== false) {
            $path = substr($path, 0, $queryPos);
        }
        $path = rawurldecode($path);

        $headers = HeaderBag::fromServer($serverData);
        $body = file_get_contents('php://input') ?: '';

        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            queryString: $queryString,
            headers: $headers,
            body: $body,
            query: $getData,
            post: $postData,
            cookies: $cookieData,
            server: $serverData,
            protocolVersion: $protocolVersion,
        );
    }
}
