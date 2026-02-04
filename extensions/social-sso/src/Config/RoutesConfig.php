<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Config;

use NoDiscard;
use Pulsar\Api\Api;

#[Api(since: '1.0.0')]
final readonly class RoutesConfig
{
    public function __construct(
        public string $loginPath,
        public string $callbackPath,
    ) {}

    /** @param array<string, mixed> $data */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            loginPath: (string) ($data['login_path'] ?? '/sso/{provider}/login'),
            callbackPath: (string) ($data['callback_path'] ?? '/sso/{provider}/callback'),
        );
    }
}
