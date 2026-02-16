<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

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
        /** @var string $login */
        $login = isset($data['login_path']) && is_string($data['login_path']) ? $data['login_path'] : '/sso/{provider}/login';
        /** @var string $callback */
        $callback = isset($data['callback_path']) && is_string($data['callback_path']) ? $data['callback_path'] : '/sso/{provider}/callback';

        return new self(
            loginPath: $login,
            callbackPath: $callback,
        );
    }
}
