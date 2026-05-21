<?php

declare(strict_types=1);

namespace Pulsar\Http;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Exception\UnsafeRedirectException;

use function in_array;
use function is_array;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * Immutable HTTP response value object.
 */
#[Api(since: '1.0.0')]
final readonly class Response
{
    public function __construct(
        public string $body = '',
        public ResponseStatus $status = ResponseStatus::OK,
        public HeaderBag $headers = new HeaderBag(),
        public string $protocolVersion = '1.1',
    ) {}

    /**
     * Return a new response with the given body.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement: Psalm does not yet infer clone() return type
     */
    #[NoDiscard]
    public function withBody(string $body): self
    {
        return clone($this, ['body' => $body]);
    }

    /**
     * Return a new response with the given status.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withStatus(ResponseStatus $status): self
    {
        return clone($this, ['status' => $status]);
    }

    /**
     * Return a new response with the given header.
     *
     * @param string|list<string> $value
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withHeader(string $name, string|array $value): self
    {
        return clone($this, ['headers' => $this->headers->with($name, $value)]);
    }

    /**
     * Return a new response with an added header value.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withAddedHeader(string $name, string $value): self
    {
        return clone($this, ['headers' => $this->headers->withAdded($name, $value)]);
    }

    /**
     * Return a new response without the given header.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withoutHeader(string $name): self
    {
        return clone($this, ['headers' => $this->headers->without($name)]);
    }

    /**
     * Return a new response with the given protocol version.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withProtocolVersion(string $version): self
    {
        return clone($this, ['protocolVersion' => $version]);
    }

    /**
     * Check if the response body is empty.
     */
    public function isEmpty(): bool
    {
        return $this->body === '';
    }

    /**
     * Get the Content-Length, if set.
     */
    public function contentLength(): ?int
    {
        $value = $this->headers->first('Content-Length');
        return $value !== null ? (int) $value : null;
    }

    /**
     * Get the Content-Type, if set.
     */
    public function contentType(): ?string
    {
        return $this->headers->first('Content-Type');
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data Data to encode as JSON
     * @param ResponseStatus $status HTTP response status
     * @param int $options JSON encoding options
     *
     * @return self
     */
    #[NoDiscard]
    public static function json(
        mixed $data,
        ResponseStatus $status = ResponseStatus::OK,
        int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): self {
        /** @var non-empty-string $body JSON_THROW_ON_ERROR guarantees string return */
        $body = json_encode($data, $options);

        return new self(
            body: $body,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'application/json; charset=utf-8',
            ]),
        );
    }

    /**
     * Create an HTML response.
     */
    #[NoDiscard]
    public static function html(
        string $html,
        ResponseStatus $status = ResponseStatus::OK,
    ): self {
        return new self(
            body: $html,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        );
    }

    /**
     * Create a plain text response.
     */
    #[NoDiscard]
    public static function text(
        string $text,
        ResponseStatus $status = ResponseStatus::OK,
    ): self {
        return new self(
            body: $text,
            status: $status,
            headers: new HeaderBag([
                'Content-Type' => 'text/plain; charset=utf-8',
            ]),
        );
    }

    /**
     * Schemes accepted by `redirect()` when the target URL is absolute.
     *
     * Anything outside this list (`javascript:`, `data:`, `vbscript:`,
     * `file:`, custom schemes, …) is rejected because it can hijack the
     * browser into executing attacker-controlled code in the user's
     * security context, exfiltrate via inline data URIs, or escape the
     * web origin entirely.
     */
    private const array ALLOWED_REDIRECT_SCHEMES = ['http', 'https'];

    /**
     * Create a redirect response.
     *
     * Validates the URL against three layered rules to prevent open-redirect
     * abuse and CRLF header smuggling (F2.5 / F2.6):
     *
     * 1. The URL must be non-empty and contain no control characters
     *    (NUL, CR, LF, ASCII < 0x20).
     * 2. If the URL is absolute (`scheme://host/...`), the scheme must be
     *    one of `ALLOWED_REDIRECT_SCHEMES`. Schemes such as `javascript:`,
     *    `data:` and `file:` are rejected outright.
     * 3. Protocol-relative URLs (`//host/...`) are rejected because they
     *    inherit the request scheme and effectively let an attacker pivot
     *    the redirect to any host without an explicit scheme.
     *
     * Optional `$allowedHosts` constrains absolute redirects to a set of
     * hostnames; pass `null` to allow any host with an accepted scheme.
     * Pass an empty array to forbid absolute redirects entirely (relative
     * paths only).
     *
     * @param list<string>|null $allowedHosts Lower-cased hostnames the
     *                                         absolute URL must match. `null`
     *                                         disables the check; `[]` forces
     *                                         relative-only redirects.
     *
     * @throws UnsafeRedirectException
     */
    #[NoDiscard]
    public static function redirect(
        string $url,
        ResponseStatus $status = ResponseStatus::Found,
        ?array $allowedHosts = null,
    ): self {
        self::assertSafeRedirectUrl($url, $allowedHosts);

        return new self(
            body: '',
            status: $status,
            headers: new HeaderBag([
                'Location' => $url,
            ]),
        );
    }

    /**
     * @param list<string>|null $allowedHosts
     *
     * @throws UnsafeRedirectException
     */
    private static function assertSafeRedirectUrl(string $url, ?array $allowedHosts): void
    {
        if ($url === '') {
            throw UnsafeRedirectException::emptyUrl();
        }

        // Reject control characters: \r, \n, NUL and any other ASCII < 0x20.
        // CR/LF would break the response into a forged second header — the
        // canonical "CRLF response splitting" attack.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw UnsafeRedirectException::controlCharacters();
        }

        if (str_starts_with($url, '//')) {
            throw UnsafeRedirectException::protocolRelative();
        }

        // Path-relative URL (begins with "/", "./", "../", or "?", "#"):
        // browsers resolve it against the current origin, so it is always
        // same-origin. Allowed by default; the `[]` allowlist still permits
        // these so that callers can opt into "relative-only" mode safely.
        if (
            str_starts_with($url, '/')
            || str_starts_with($url, './')
            || str_starts_with($url, '../')
            || str_starts_with($url, '?')
            || str_starts_with($url, '#')
        ) {
            return;
        }

        // From here on the URL is treated as absolute. parse_url is lenient
        // (e.g. it accepts `javascript:alert(1)`), so we must validate the
        // scheme explicitly against the allowlist.
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        if ($scheme === '' || !in_array($scheme, self::ALLOWED_REDIRECT_SCHEMES, true)) {
            throw UnsafeRedirectException::disallowedScheme(
                $scheme === '' ? 'unknown' : $scheme,
                'http and https',
            );
        }

        if ($allowedHosts !== null) {
            $host = is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : '';
            $normalizedAllowlist = [];

            foreach ($allowedHosts as $allowed) {
                $normalizedAllowlist[] = strtolower($allowed);
            }

            if ($host === '' || !in_array($host, $normalizedAllowlist, true)) {
                throw UnsafeRedirectException::notInAllowlist($url);
            }
        }
    }

    /**
     * Create an empty response (204 No Content).
     */
    #[NoDiscard]
    public static function noContent(): self
    {
        return new self(
            body: '',
            status: ResponseStatus::NoContent,
        );
    }

    /**
     * Create a 422 JSON response for validation errors.
     *
     * @param list<array{field: string, message: string, rule: string}> $violations
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
            status: ResponseStatus::UnprocessableEntity,
        );
    }
}
