<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_merge;
use function dns_get_record;
use function explode;
use function file_get_contents;
use function filter_var;
use function gethostbyname;
use function http_build_query;
use function implode;
use function in_array;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_context_create;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function usleep;

/**
 * HTTP client using PHP native streams.
 *
 * No external dependencies (curl, Guzzle, etc.). Built-in SSRF protection
 * blocks requests to private/reserved IP ranges by default.
 * @api
 */
#[Api(since: '1.0.0')]
final class HttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientConfig $config = new HttpClientConfig(),
    ) {}

    /**
     * Create a fluent request builder.
     */
    #[NoDiscard]
    public function pending(): PendingRequest
    {
        return new PendingRequest($this);
    }

    #[Override]
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->send('GET', $url, $options);
    }

    #[Override]
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->send('POST', $url, $options);
    }

    #[Override]
    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->send('PUT', $url, $options);
    }

    #[Override]
    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->send('PATCH', $url, $options);
    }

    #[Override]
    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->send('DELETE', $url, $options);
    }

    #[Override]
    public function head(string $url, array $options = []): HttpResponse
    {
        return $this->send('HEAD', $url, $options);
    }

    #[Override]
    public function options(string $url, array $options = []): HttpResponse
    {
        return $this->send('OPTIONS', $url, $options);
    }

    /**
     * Send an HTTP request.
     *
     * @param array<string, mixed> $options
     *
     * @throws HttpClientException
     */
    private function send(string $method, string $url, array $options): HttpResponse
    {
        $resolvedUrl = $this->resolveUrl($url, $options);

        /** @var float $timeout */
        $timeout = $options['timeout'] ?? $this->config->timeout;
        /** @var int $retries */
        $retries = $options['retries'] ?? $this->config->retries;
        /** @var float $retryDelay */
        $retryDelay = $options['retry_delay'] ?? $this->config->retryDelay;

        $lastException = null;
        $attempts = $retries + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->sendFollowingRedirects($method, $resolvedUrl, $options, $timeout);
            } catch (HttpClientException $e) {
                $lastException = $e;

                if ($attempt < $attempts) {
                    // Exponential backoff: delay * 2^(attempt-1)
                    $delayMicroseconds = (int) ($retryDelay * 1_000_000 * (2 ** ($attempt - 1)));
                    usleep($delayMicroseconds);
                }
            }
        }

        throw $lastException ?? HttpClientException::connectionFailed($resolvedUrl, 'Unknown error');
    }

    /**
     * Send a request, following redirects in PHP so every hop is SSRF-validated.
     *
     * The stream wrapper's own redirect following is disabled (see doSend); this
     * loop re-runs guardAgainstSsrf() — scheme check, IP validation, and DNS
     * pinning — on the initial URL and on every 3xx Location before connecting,
     * closing the SSRF-via-redirect bypass. The hostname URL is what redirect
     * targets resolve against; only the connection uses the pinned host→IP URL.
     *
     * @param array<string, mixed> $options
     *
     * @throws HttpClientException
     */
    private function sendFollowingRedirects(string $method, string $url, array $options, float $timeout): HttpResponse
    {
        $maxRedirects = $this->config->maxRedirects;
        $currentUrl = $url;
        $currentMethod = strtoupper($method);
        $currentOptions = $options;

        for ($hop = 0; ; $hop++) {
            $target = $this->config->ssrfProtection
                ? $this->guardAgainstSsrf($currentUrl)
                : $currentUrl;

            $response = $this->doSend($currentMethod, $target, $currentOptions, $timeout);

            if ($maxRedirects <= 0 || !RedirectResolver::isRedirect($response->status())) {
                return $response;
            }

            $location = $response->header('Location');
            if ($location === null || trim($location) === '') {
                return $response;
            }

            if ($hop >= $maxRedirects) {
                throw HttpClientException::tooManyRedirects($maxRedirects);
            }

            $currentUrl = RedirectResolver::resolve($currentUrl, $location);
            [$currentMethod, $currentOptions] = $this->rewriteForRedirect(
                $response->status(),
                $currentMethod,
                $currentOptions,
            );
        }
    }

    /**
     * Rewrite the method and body for the next redirect hop.
     *
     * 307/308 preserve the method and body; 301/302/303 downgrade a body-bearing
     * method to GET and drop the body (the default curl/browser behaviour).
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function rewriteForRedirect(int $status, string $method, array $options): array
    {
        if ($status === 307 || $status === 308 || $method === 'GET' || $method === 'HEAD') {
            return [$method, $options];
        }

        unset($options['body']);

        return ['GET', $options];
    }

    /**
     * Perform the actual HTTP request using PHP streams.
     *
     * @param array<string, mixed> $options
     *
     * @throws HttpClientException
     */
    private function doSend(string $method, string $url, array $options, float $timeout): HttpResponse
    {
        $headers = $this->mergeHeaders($options);
        $body = $this->resolveBody($options);

        if ($body !== null && !isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        $httpOptions = [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'timeout' => $timeout,
            // Never let the stream wrapper follow redirects: it would re-resolve
            // and connect to the 3xx target with no SSRF re-validation (cloud
            // metadata / internal hosts). Redirects are followed explicitly in
            // sendFollowingRedirects(), which re-runs guardAgainstSsrf() on every
            // hop. max_redirects=1 means "no redirects" for the http wrapper.
            'follow_location' => 0,
            'max_redirects' => 1,
            'ignore_errors' => true,
            'protocol_version' => '1.1',
        ];

        if ($body !== null) {
            $httpOptions['content'] = $body;
        }

        if ($this->config->proxy !== null) {
            $httpOptions['proxy'] = $this->config->proxy;
            $httpOptions['request_fulluri'] = true;
        }

        $sslOptions = [
            'verify_peer' => $this->config->verifySsl,
            'verify_peer_name' => $this->config->verifySsl,
        ];

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => $sslOptions,
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            $error = error_get_last();
            $errorMessage = $error['message'] ?? 'Unknown error';

            if (str_contains(strtolower($errorMessage), 'timed out')
                || str_contains(strtolower($errorMessage), 'timeout')) {
                throw HttpClientException::timeout($url, $timeout);
            }

            if (str_contains(strtolower($errorMessage), 'ssl')
                || str_contains(strtolower($errorMessage), 'certificate')) {
                throw HttpClientException::sslError($url, $errorMessage);
            }

            throw HttpClientException::connectionFailed($url, $errorMessage);
        }

        // Enforce max response size
        if ($this->config->maxResponseSize > 0 && strlen($responseBody) > $this->config->maxResponseSize) {
            throw HttpClientException::responseTooLarge($this->config->maxResponseSize);
        }

        // Parse response headers populated by file_get_contents() with the http:// wrapper
        /** @var list<string> $responseHeaders */
        $responseHeaders = http_get_last_response_headers();
        $statusCode = $this->parseStatusCode($responseHeaders);
        $parsedHeaders = $this->parseResponseHeaders($responseHeaders);

        return HttpResponse::fromRaw($statusCode, $parsedHeaders, $responseBody);
    }

    /**
     * Resolve the full URL, applying base URL and query parameters.
     *
     * @param array<string, mixed> $options
     */
    private function resolveUrl(string $url, array $options): string
    {
        // Apply base URL for relative paths
        if (!str_contains($url, '://') && $this->config->baseUrl !== null) {
            $url = rtrim($this->config->baseUrl, '/') . '/' . ltrim($url, '/');
        }

        // Append query parameters
        /** @var array<string, string> $query */
        $query = $options['query'] ?? [];
        if ($query !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query);
        }

        return $url;
    }

    /**
     * Merge request headers with defaults and config.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private function mergeHeaders(array $options): array
    {
        $headers = $this->config->defaultHeaders;

        /** @var array<string, string> $requestHeaders */
        $requestHeaders = $options['headers'] ?? [];

        return array_merge($headers, $requestHeaders);
    }

    /**
     * Extract or build the request body from options.
     *
     * @param array<string, mixed> $options
     */
    private function resolveBody(array $options): ?string
    {
        if (isset($options['body']) && is_string($options['body'])) {
            return $options['body'];
        }

        return null;
    }

    /**
     * Guard against Server-Side Request Forgery (SSRF) with DNS pinning.
     *
     * Resolves the hostname to an IP, validates it against private/reserved ranges,
     * then replaces the hostname in the URL with the resolved IP to prevent DNS
     * rebinding TOCTOU attacks. The original Host header is preserved via the
     * returned URL mutation.
     *
     * Supports both IPv4 (gethostbyname) and IPv6 (dns_get_record AAAA).
     *
     * @return string The URL with the hostname replaced by the pinned IP
     *
     * @throws HttpClientException If the resolved IP is in a blocked range
     */
    private function guardAgainstSsrf(string $url): string
    {
        $parsed = parse_url($url);

        // Enforce http(s) before anything else: a redirect Location of
        // file:///etc/passwd or gopher://… parses with no host and would
        // otherwise slip past the IP checks below and be fetched locally.
        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw HttpClientException::disallowedScheme($url, $scheme);
        }

        $host = $parsed['host'] ?? null;

        if ($host === null) {
            return $url;
        }

        // Block localhost synonyms
        $loweredHost = strtolower($host);
        if (in_array($loweredHost, ['localhost', '0.0.0.0', '[::1]', '[::0]', '[::]'], true)) {
            throw HttpClientException::ssrfBlocked($host, $host);
        }

        // If host is already an IP literal, validate directly (no DNS pinning needed)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateIp($host)) {
                throw HttpClientException::ssrfBlocked($host, $host);
            }

            return $url;
        }

        // Bracketed IPv6 literal (e.g. [2001:db8::1])
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $bareIp = substr($host, 1, -1);
            if (filter_var($bareIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                if ($this->isPrivateIp($bareIp)) {
                    throw HttpClientException::ssrfBlocked($host, $bareIp);
                }

                return $url;
            }
        }

        // Resolve DNS and pin the IP to prevent TOCTOU rebinding
        $resolvedIp = $this->resolveDns($host);

        if ($resolvedIp === null) {
            // DNS resolution failed: allow through (DNS may not be available)
            return $url;
        }

        if ($this->isPrivateIp($resolvedIp)) {
            throw HttpClientException::ssrfBlocked($host, $resolvedIp);
        }

        // Pin the connection: replace hostname with resolved IP in the URL
        // to ensure file_get_contents() connects to the validated IP, not a
        // potentially re-resolved DNS name (TOCTOU mitigation).
        return $this->pinHostToIp($url, $host, $resolvedIp);
    }

    /**
     * Resolve a hostname to an IP address, supporting both IPv4 and IPv6.
     *
     * Tries IPv4 (A record) first via gethostbyname(), then falls back to
     * IPv6 (AAAA record) via dns_get_record().
     */
    private function resolveDns(string $host): ?string
    {
        // Try IPv4 first
        $ipv4 = gethostbyname($host);
        if ($ipv4 !== $host) {
            return $ipv4;
        }

        // Try IPv6 via AAAA records
        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false && $records !== []) {
            /** @var mixed $ipv6 */
            $ipv6 = $records[0]['ipv6'] ?? null;
            return is_string($ipv6) ? $ipv6 : null;
        }

        return null;
    }

    /**
     * Replace the hostname in a URL with a resolved IP for DNS pinning.
     *
     * For IPv6 addresses, wraps the IP in brackets as required by URL syntax.
     */
    private function pinHostToIp(string $url, string $originalHost, string $resolvedIp): string
    {
        // IPv6 addresses must be bracketed in URLs
        $replacement = filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $resolvedIp . ']'
            : $resolvedIp;

        // Replace only the host portion (preserving port, path, etc.)
        // The host appears after :// and before the next / or :
        $hostInUrl = $originalHost;
        $parsed = parse_url($url);
        if (isset($parsed['port'])) {
            $hostInUrl .= ':' . $parsed['port'];
            $replacement .= ':' . $parsed['port'];
        }

        // Replace the first occurrence of host in the URL (after the scheme)
        $schemeEnd = strpos($url, '://');
        if ($schemeEnd === false) {
            return $url;
        }

        $prefix = substr($url, 0, $schemeEnd + 3);
        $rest = substr($url, $schemeEnd + 3);
        $hostPos = strpos($rest, $hostInUrl);

        if ($hostPos === false) {
            return $url;
        }

        return $prefix . substr($rest, 0, $hostPos) . $replacement . substr($rest, $hostPos + strlen($hostInUrl));
    }

    /**
     * Check if an IP address is private, loopback, or reserved.
     */
    private function isPrivateIp(string $ip): bool
    {
        // Use PHP's built-in filter for private/reserved ranges
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * Parse HTTP status code from response headers.
     *
     * @param list<string> $headers
     */
    private function parseStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                $parts = explode(' ', $header, 3);
                return (int) ($parts[1] ?? 200);
            }
        }

        return 200;
    }

    /**
     * Parse response headers into an associative array.
     *
     * Handles multiple headers with the same name by using the last status
     * line (for redirects) and merging other headers.
     *
     * @param list<string> $rawHeaders
     *
     * @return array<string, string|list<string>>
     */
    private function parseResponseHeaders(array $rawHeaders): array
    {
        $headers = [];

        foreach ($rawHeaders as $header) {
            // Skip status lines
            if (str_starts_with($header, 'HTTP/')) {
                continue;
            }

            $colonPos = strpos($header, ':');
            if ($colonPos === false) {
                continue;
            }

            $name = trim(substr($header, 0, $colonPos));
            $value = trim(substr($header, $colonPos + 1));

            if (isset($headers[$name])) {
                $existing = $headers[$name];
                if (is_string($existing)) {
                    $headers[$name] = [$existing, $value];
                } else {
                    /** @var list<string> $existing */
                    $headers[$name] = [...$existing, $value];
                }
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
