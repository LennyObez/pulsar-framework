<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function in_array;

/**
 * A single mTLS identity mapping entry.
 *
 * Maps a certificate SAN to a service name, trust level, and allowed methods.
 * Declared in configuration and compiled at build time.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IdentityMappingEntry
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public string $name,
        public string $trustLevel = 'internal',
        public array $allowedMethods = ['*'],
    ) {}

    /**
     * @param array{
     *     name?: string,
     *     trust_level?: string,
     *     allowed_methods?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::string($data['name'] ?? null),
            trustLevel: Coerce::string($data['trust_level'] ?? null, 'internal'),
            allowedMethods: Coerce::listOfString($data['allowed_methods'] ?? null, ['*']),
        );
    }

    /**
     * Whether this identity is allowed to call the given fully-qualified method.
     */
    public function isMethodAllowed(string $method): bool
    {
        if ($this->allowedMethods === ['*']) {
            return true;
        }

        return in_array($method, $this->allowedMethods, true);
    }
}
