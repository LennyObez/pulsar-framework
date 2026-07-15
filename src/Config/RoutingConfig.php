<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Typed configuration DTO for `config/routing.php`.
 *
 * Holds request-URL canonicalization policy. The defaults are chosen so that
 * an application that ships no `config/routing.php` behaves exactly as before:
 * slash canonicalization is OFF, so this DTO's mere presence changes nothing.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class RoutingConfig
{
    /**
     * @param bool $redirectToCanonicalPath When true, a request whose path is
     *     not already the canonical registered form (repeated slashes collapsed,
     *     no trailing slash) is permanently redirected to that form before the
     *     router runs. OFF by default: the router keeps matching the forgiving
     *     slash family, so enabling this is an opt-in, backwards-compatible
     *     tightening. The root path "/" is always exempt.
     */
    public function __construct(
        public bool $redirectToCanonicalPath = false,
    ) {}

    /**
     * @param array{
     *     redirect_to_canonical_path?: bool|int|string,
     * } $data Raw array from config/routing.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $envValue = $environment->get('PULSAR_ROUTING_REDIRECT_TO_CANONICAL_PATH');

        $redirect = $envValue !== null
            ? in_array($envValue, ['true', '1'], true)
            : (bool) ($data['redirect_to_canonical_path'] ?? false);

        return new self(
            redirectToCanonicalPath: $redirect,
        );
    }
}
