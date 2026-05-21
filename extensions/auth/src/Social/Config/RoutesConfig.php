<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Config;

use NoDiscard;
use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
final readonly class RoutesConfig
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $loginPath,
        public string $callbackPath,
    ) {}

    /**
     * @param array{
     *     login_path?: string,
     *     callback_path?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            loginPath: $data['login_path'] ?? '/sso/{provider}/login',
            callbackPath: $data['callback_path'] ?? '/sso/{provider}/callback',
        );
    }
}
