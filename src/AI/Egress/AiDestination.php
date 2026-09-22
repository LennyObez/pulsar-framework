<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use NoDiscard;
use Pulsar\AI\Exception\AiEgressRefusedException;
use Pulsar\Api\Api;

use function is_string;
use function parse_url;
use function strtolower;

/**
 * Where an AI client sends bytes, as declared by whoever built it.
 *
 * WHY THIS IS DECLARED AND NOT OBSERVED, stated plainly because the difference
 * is the limit of the whole destination control. The obvious implementation asks
 * the client: {@see \Pulsar\AI\AiClientInterface::providerName()} returns
 * `'anthropic'`, `'openai'`, `'ollama'`. Every one of those is a string literal
 * compiled into the provider class, and none of them is evidence about the
 * socket — `OllamaProvider` would answer `'ollama'` however its `$baseUrl` were
 * set. ADR-0041 records that defect in its general form: a capability proved by a
 * resolvable name is not proved.
 *
 * The providers keep `$baseUrl` private and expose no accessor, so nothing above
 * the transport can read the real endpoint. Rather than pretend otherwise, this
 * type takes the base URL from the composition root — the same value, from the
 * same place, that was handed to the provider's constructor — and derives the
 * host from it. The claim the guard can then make is exact: *the endpoint the
 * operator was told about is on the allow-list*. It is not "the socket went
 * there". {@see GuardedAiClient} narrows the gap it can with a coherence check
 * against `providerName()`, which catches the misconfiguration case — a
 * destination declared for one provider wrapped around another — and cannot
 * catch a provider lying about its own name.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiDestination
{
    /**
     * @param string $providerName Must equal the wrapped client's own
     *                             `providerName()`; {@see GuardedAiClient}
     *                             enforces that on every call
     * @param string $host         Lower-cased host, derived from the base URL
     * @param string $baseUrl      The endpoint as configured, kept for the record
     */
    private function __construct(
        public string $providerName,
        public string $host,
        public string $baseUrl,
    ) {}

    /**
     * Derive a destination from the base URL a provider was constructed with.
     *
     * The only way to build one, so that {@see $host} is always a function of
     * {@see $baseUrl} rather than a second thing someone typed.
     *
     * A URL `parse_url()` cannot find a host in is refused rather than reduced to
     * an empty host. The reachable case is an operator writing the base URL the
     * way people say it out loud: `parse_url('api.anthropic.com/v1')` yields
     * `['path' => 'api.anthropic.com/v1']` and no host at all, because without a
     * scheme or a leading `//` the whole string is a path. A destination whose
     * host silently came out empty would match nothing on today's exact-equality
     * matcher — failing closed, but for a reason nobody could see — and would be
     * a wildcard under any looser matcher added later. Refusing at construction
     * makes the misconfiguration a startup error instead.
     *
     * A host with a port and no scheme (`localhost:11434`) parses correctly and
     * is accepted; that shape was checked rather than assumed.
     *
     * @throws AiEgressRefusedException When no host can be derived from the URL
     */
    #[NoDiscard]
    public static function fromBaseUrl(string $providerName, string $baseUrl): self
    {
        $parsed = parse_url($baseUrl);
        $host = ($parsed !== false && isset($parsed['host']) && is_string($parsed['host']))
            ? $parsed['host']
            : '';

        if ($host === '') {
            throw AiEgressRefusedException::undeterminableDestination($providerName, $baseUrl);
        }

        return new self($providerName, strtolower($host), $baseUrl);
    }

    /**
     * How this destination is named in a refusal message and an audit record.
     */
    #[NoDiscard]
    public function describe(): string
    {
        return $this->providerName . ' @ ' . $this->host;
    }
}
