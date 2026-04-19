<?php

declare(strict_types=1);

namespace Pulsar\Http;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Exception\BodyTooLargeException;
use WeakMap;

use function array_diff_key;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_validate;
use function str_contains;
use function strlen;

use const JSON_THROW_ON_ERROR;

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
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement: Psalm does not yet infer clone() return type
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
    /**
     * F7.17: hard cap on the body size that `json()` will pass to
     * `json_decode`. The outer `Request::fromGlobals()` already
     * applies a body-size cap (default 8 MiB) at read time, but a
     * caller constructing a `Request` instance directly with a hand-
     * crafted body (tests, internal dispatch) bypasses that cap. This
     * second-line guard ensures the JSON parser never sees more than
     * a documented bound regardless of how the body got here.
     */
    private const int JSON_BODY_DECODE_LIMIT = 8_388_608; // 8 MiB

    private function decodeJsonBody(): array
    {
        $contentType = $this->header('Content-Type');
        if ($contentType === null || !str_contains($contentType, 'application/json')) {
            return [];
        }

        // F7.17: refuse to decode oversize bodies. Returning an empty
        // array keeps the `json()` contract intact (callers already
        // handle the empty-array case for invalid JSON / wrong content
        // type) without raising mid-request.
        if ($this->body === '' || strlen($this->body) > self::JSON_BODY_DECODE_LIMIT) {
            return [];
        }

        if (!json_validate($this->body)) {
            return [];
        }

        try {
            $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> */
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * Merge all input sources: query + post + json (json > post > query precedence).
     *
     * Results are memoized per-instance via a WeakMap so that repeated calls
     * from input(), has(), filled(), only(), except() do not rebuild the array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var WeakMap<self, array<string, mixed>>|null $cache */
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

        $result = [...$this->query, ...$this->post, ...$this->json()];
        $cache[$this] = $result;

        return $result;
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
     * Default upper bound on the request body size that `fromGlobals` will
     * read from `php://input` (8 MiB).
     *
     * Without a ceiling, an attacker can post an arbitrarily large body
     * and exhaust worker memory before the application even begins to
     * parse the request — a cheap DoS vector. The default matches the
     * common `post_max_size` ini value of 8 MiB and can be raised or
     * lowered explicitly per call (F2.8).
     */
    public const int DEFAULT_MAX_BODY_BYTES = 8_388_608;

    /**
     * Create a Request from PHP superglobals.
     *
     * Reads `php://input` with an explicit upper bound: anything larger
     * than `$maxBodyBytes` raises `BodyTooLargeException` so the kernel
     * can reply with 413 Payload Too Large. Pass `0` to disable the cap
     * (rarely safe — typically only useful for trusted internal jobs).
     *
     * @param array<string, mixed>|null $get
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>|null $cookies
     * @param array<string, mixed>|null $server
     * @param int $maxBodyBytes Upper bound on the body size; 0 disables.
     *
     * @throws BodyTooLargeException When `php://input` exceeds the cap or
     *                               when the declared `Content-Length`
     *                               already exceeds the cap.
     */
    #[NoDiscard]
    public static function fromGlobals(
        ?array $get = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $server = null,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
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

        $declaredLength = self::resolveContentLength($serverData);

        if ($maxBodyBytes > 0 && $declaredLength > $maxBodyBytes) {
            // Fail fast on Content-Length: no point allocating a stream
            // for a body the application is going to refuse anyway.
            throw new BodyTooLargeException($maxBodyBytes, $declaredLength);
        }

        $body = self::readBoundedBody('php://input', $maxBodyBytes, $declaredLength);

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

    /**
     * @param array<string, mixed> $serverData
     */
    private static function resolveContentLength(array $serverData): int
    {
        $raw = $serverData['CONTENT_LENGTH'] ?? $serverData['HTTP_CONTENT_LENGTH'] ?? null;

        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }

        return 0;
    }

    /**
     * Read a request-body stream, refusing anything past `$maxBodyBytes`.
     *
     * Streaming the body in chunks (rather than `file_get_contents`) keeps
     * the failure mode loud: as soon as the cumulative read exceeds the
     * cap we raise — we never allocate space for the over-sized payload.
     *
     * Visible to tests so the bounded-read logic can be exercised against
     * arbitrary stream URLs (a temp file, a memory stream, …) without
     * relying on `php://input` being writable from PHP test code.
     *
     * @throws BodyTooLargeException
     *
     * @internal
     */
    public static function readBoundedBody(string $stream, int $maxBodyBytes, int $declaredLength): string
    {
        $handle = @fopen($stream, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            if ($maxBodyBytes <= 0) {
                $body = '';

                while (!feof($handle)) {
                    $chunk = fread($handle, 8_192);
                    if ($chunk === false) {
                        break;
                    }
                    $body .= $chunk;
                }

                return $body;
            }

            // Read at most $maxBodyBytes + 1 to detect overflow without
            // allocating the entire over-sized payload.
            $body = '';
            $remaining = $maxBodyBytes + 1;

            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, $remaining < 8_192 ? $remaining : 8_192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $body .= $chunk;
                $remaining = $maxBodyBytes + 1 - strlen($body);
            }

            if (strlen($body) > $maxBodyBytes) {
                throw new BodyTooLargeException($maxBodyBytes, $declaredLength);
            }

            return $body;
        } finally {
            fclose($handle);
        }
    }
}
