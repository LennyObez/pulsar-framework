<?php

declare(strict_types=1);

namespace Pulsar\Auth\Token;

use Pulsar\Api\Api;
use SensitiveParameter;

/**
 * The result of creating a personal access token.
 *
 * Contains the plaintext token (shown only once) and the persisted token record.
 * The plaintext value must be displayed to the user immediately and never stored.
 */
#[Api(since: '1.0.0')]
final readonly class PersonalAccessTokenResult
{
    public function __construct(
        public PersonalAccessToken $token,
        #[SensitiveParameter]
        public string $plaintext,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'token_id' => $this->token->id,
            'name' => $this->token->name,
            'plaintext' => '[REDACTED]',
        ];
    }
}
